function blog_editor_init(name, editor_config) {
	if (editor_config === undefined) {
		editor_config = {};
	}
	
	// autosave: what is typed is saved by itself a moment after the typing stops, as the
	//           button saves it
	// draft_key: the name under which this browser keeps what is not saved yet, so that a
	//           save that fails, or a page that is closed, does not take the text with it
	editor_config = $.extend({
		type: 'blog',
		autosave: false,
		draft_key: null
	}, editor_config);
	
	var input_title = $("#input-" + name + "_title");
	var input_tags = $("#input-" + name + "_tags");
	var input_content_md = $("#input-" + name + "_content_md");
	var input_is_hidden = $("#input-" + name + "_is_hidden");
	var this_form = input_content_md[0].form;
	
	var is_saved;
	var last_save_done = true;
	
	// init buttons
	var save_btn = $('<button type="button" class="btn btn-sm"></button>');
	var preview_btn = $('<button type="button" class="btn btn-secondary btn-sm"><span class="glyphicon glyphicon-eye-open"></span></button>');
	var bold_btn = $('<button type="button" class="btn btn-secondary btn-sm ml-2"><span class="glyphicon glyphicon-bold"></span></button>');
	var italic_btn = $('<button type="button" class="btn btn-secondary btn-sm"><span class="glyphicon glyphicon-italic"></span></button>');
	
	save_btn.tooltip({ container: 'body', title: '保存 (Ctrl-S / ⌘S)' });
	preview_btn.tooltip({ container: 'body', title: '预览 (Ctrl-D)' 	});
	bold_btn.tooltip({ container: 'body', title: '粗体 (Ctrl-B)' });
	italic_btn.tooltip({ container: 'body', title: '斜体 (Ctrl-I)' });
	
	var all_btn = [save_btn, preview_btn, bold_btn, italic_btn];
	
	// init toolbar
	var toolbar = $('<div class="btn-toolbar"></div>');
	toolbar.append($('<div class="btn-group"></div>')
		.append(save_btn)
		.append(preview_btn)
	);
	toolbar.append($('<div class="btn-group"></div>')
		.append(bold_btn)
		.append(italic_btn)
	);
	// what became of the last save, said beside the buttons
	var status = $('<span class="blog-editor-status small text-muted align-self-center ml-3" id="status-' + name + '"></span>');
	toolbar.append(status);
	function tell(text, bad) {
		status.text(text).toggleClass('text-danger', !!bad).toggleClass('text-muted', !bad);
	}
	function clock() {
		var now = new Date();
		var two = function(n) {
			return (n < 10 ? '0' : '') + n;
		};
		return two(now.getHours()) + ':' + two(now.getMinutes()) + ':' + two(now.getSeconds());
	}
	
	function set_saved(val) {
		is_saved = val;
		if (val) {
			save_btn.removeClass('btn-warning');
			save_btn.addClass('btn-success');
			save_btn.html('<span class="glyphicon glyphicon-saved"></span>');
			before_window_unload_message = null;
		} else {
			save_btn.removeClass('btn-success');
			save_btn.addClass('btn-warning');
			save_btn.html('<span class="glyphicon glyphicon-save"></span>');
			// an editor that saves by itself sends its last words on the way out instead of asking
			before_window_unload_message = editor_config.autosave ? null : '您所编辑的内容尚未保存';
		}
	}
	function set_preview_status(status) {
		// 0: normal
		// 1: loading
		// 2: loaded
		if (status == 0) {
			preview_btn.removeClass('active');
			for (var i = 0; i < all_btn.length; i++) {
				if (all_btn[i] != preview_btn) {
					all_btn[i].prop('disabled', false);
				}
			}
		} else if (status == 1) {
			for (var i = 0; i < all_btn.length; i++) {
				if (all_btn[i] != preview_btn) {
					all_btn[i].prop('disabled', true);
				}
			}
			preview_btn.addClass('active');
		}
	}
	
	set_saved(true);
	
	// init codemirror
	input_content_md.wrap('<div class="blog-content-md-editor"></div>');
	var blog_contend_md_editor = input_content_md.parent();
	input_content_md.before($('<div class="blog-content-md-editor-toolbar"></div>')
		.append(toolbar)
	);
	input_content_md.wrap('<div class="blog-content-md-editor-in"></div>');
	
	var codeeditor;
	if (editor_config.type == 'blog') {
		codeeditor = CodeMirror.fromTextArea(input_content_md[0], {
			mode: 'gfm',
			lineNumbers: true,
			matchBrackets: true,
			lineWrapping: true,
			styleActiveLine: true,
			theme: 'default'
		});
	} else if (editor_config.type == 'slide') {
		codeeditor = CodeMirror.fromTextArea(input_content_md[0], {
			mode: 'plain',
			lineNumbers: true,
			matchBrackets: true,
			lineWrapping: true,
			styleActiveLine: true,
			theme: 'default'
		});
	}
	
	function preview(html) {
		var iframe = $('<iframe frameborder="0"></iframe>');
		blog_contend_md_editor.append(
			$('<div class="blog-content-md-editor-preview" style="display: none;"></div>')
				.append(iframe)
		);
		var iframe_document = iframe[0].contentWindow.document;
		iframe_document.open();
		iframe_document.write(html);
		iframe_document.close();
		$(iframe_document).bind('keydown', 'ctrl+d', function() {
			preview_btn.click();
			return false;
		});
		
		blog_contend_md_editor.find('.blog-content-md-editor-in').slideUp('fast');
		blog_contend_md_editor.find('.blog-content-md-editor-preview').slideDown('fast', function() {
			set_preview_status(2);
			iframe.focus(); 
			iframe.find('body').focus();
		});
	}
	// ---- what is not saved yet is kept in this browser
	function typed() {
		return {title: input_title.val(), tags: input_tags.val(), content_md: codeeditor.getValue()};
	}
	function same(a, b) {
		return a.title === b.title && a.tags === b.tags && a.content_md === b.content_md;
	}
	// a short mark of a text: whether the text that a draft was begun from is still the one that is saved
	function mark(text) {
		var h = 0;
		for (var i = 0; i < text.length; i++) {
			h = (h * 31 + text.charCodeAt(i)) | 0;
		}
		return text.length + ':' + h;
	}
	var saved_mark = null;
	var draft_timer = null;
	function keep_draft() {
		if (editor_config.draft_key && !is_saved) {
			uojDraft.write(editor_config.draft_key, $.extend(typed(), {base: saved_mark}));
		}
	}
	function drop_draft() {
		clearTimeout(draft_timer);
		if (editor_config.draft_key) {
			uojDraft.remove(editor_config.draft_key);
		}
	}
	var kept_note = function() {
		return editor_config.draft_key ? '内容留在了这个浏览器里，重新打开这个页面时会恢复。' : '';
	};
	
	// ---- saving by itself
	var autosave_timer = null;
	var save_again = false;
	// A save that failed is tried again, each time after a longer wait: a server that is away
	// for a while is not asked every other second. A page that has to be opened anew stops.
	var autosave_wait = 2500;
	var autosave_stopped = false;
	function schedule_autosave() {
		if (!editor_config.autosave || autosave_stopped) {
			return;
		}
		clearTimeout(autosave_timer);
		autosave_timer = setTimeout(function() {
			if (!is_saved) {
				save({auto: true});
			}
		}, autosave_wait);
	}
	// whether what is typed now was sent on the way out already: leaving a page is told twice
	var sent_on_the_way_out = false;
	function changed() {
		autosave_wait = 2500;
		sent_on_the_way_out = false;
		set_saved(false);
		if (editor_config.draft_key) {
			clearTimeout(draft_timer);
			draft_timer = setTimeout(keep_draft, 400);
		}
		if (editor_config.autosave) {
			tell('有还没保存的修改…');
			schedule_autosave();
		}
	}
	
	function save(config) {
		if (config == undefined) {
			config = {};
		}
		config = $.extend({
			need_preview: false,
			auto: false,
			fail: function() {
			},
			done: function() {
			}
		}, config);
		
		if (!last_save_done) {
			// the save that is under way does not have what was typed since: another follows it
			save_again = true;
			config.fail();
			config.done();
			return;
		}
		last_save_done = false;
		save_again = false;
		clearTimeout(autosave_timer);
		
		if (config.need_preview) {
			set_preview_status(1);
		}
		
		var sent = typed();
		var post_data = {};
		$($(this_form).serializeArray()).each(function() {
			post_data[this["name"]] = this["value"];
		});
		if (config.need_preview) {
			post_data['need_preview'] = 'on';
		}
		if (config.auto) {
			post_data['autosave'] = 'on';
		}
		post_data["save-" + name] = '';
		tell('正在保存…');
		// what is said when the text is not saved: beside the buttons when the editor saved by
		// itself, and in a dialog when somebody asked for it
		var not_saved = function(text, shown) {
			keep_draft();
			autosave_wait = Math.min(autosave_wait * 3, 60000);
			tell(text, true);
			if (!config.auto && shown !== false) {
				alert(shown === undefined ? text : shown);
			}
			if (config.need_preview) {
				set_preview_status(0);
			}
			config.fail();
		};
		
		$.ajax({
			type : 'POST',
			data : post_data,
			url : window.location.href,
			success : function(data) {
				try {
					data = JSON.parse(data)
				} catch (e) {
					// not an answer of the editor: the login ran out, or the server failed
					not_saved('没有保存下来：服务器没有正常应答，可能是登录过期了。' + kept_note(), editor_config.draft_key ? undefined : data);
					return;
				}
				if (data.expired) {
					autosave_stopped = true;
					not_saved('没有保存下来：这个页面打开之后你重新登录过。' + (editor_config.draft_key ? '请刷新页面，' + kept_note() : '请把内容复制下来，刷新页面后再保存。'));
					return;
				}
				var ok = true;
				$(['title', 'content_md', 'tags']).each(function() {
					ok &= showErrorHelp(name + '_' + this, data[this]);
				});
				if (!ok) {
					// what is wrong is said at the field it is wrong in
					not_saved('没有保存：请先改正标出的问题。', false);
					return;
				}
				if (data.extra !== undefined) {
					not_saved(data.extra);
					return;
				}
				
				// what was typed while this was on its way is not saved yet
				if (same(sent, typed())) {
					set_saved(true);
					drop_draft();
				} else {
					save_again = true;
				}
				saved_mark = mark(sent.content_md);
				autosave_wait = 2500;
				tell((config.auto ? '已自动保存 ' : '已保存 ') + clock());
				
				if (config.need_preview) {
					preview(data.html);
				}
				
				if (data.blog_write_url) {
					window.history.replaceState({}, document.title, data.blog_write_url);
				}
				if (data.blog_url) {
					$('#a-' + name + '_view_blog').attr('href', data.blog_url).show();
				}
			}
		}).fail(function() {
			not_saved('没有保存下来：连不上服务器。' + kept_note());
		}).always(function() {
			last_save_done = true;
			config.done();
			if (save_again || (editor_config.autosave && !is_saved)) {
				schedule_autosave();
			}
		});
	}
	function add_around(sl, sr) {
		codeeditor.replaceSelection(sl + codeeditor.getSelection() + sr);
	}
	
	// event
	codeeditor.on('change', function() {
		codeeditor.save();
		changed();
	});
	$.merge(input_title, input_tags).on('input', function() {
		changed();
	});
	save_btn.click(function() {
		save();
	});
	preview_btn.click(function() {
		if (preview_btn.hasClass('active')) {
			set_preview_status(0);
			blog_contend_md_editor.find('.blog-content-md-editor-in').slideDown('fast');
			blog_contend_md_editor.find('.blog-content-md-editor-preview').slideUp('fast', function() {
				$(this).remove();
			});
			codeeditor.focus();
		} else {
			save({need_preview: true});
		}
	});
	bold_btn.click(function() {
		add_around("**", "**");
		codeeditor.focus();
	});
	italic_btn.click(function() {
		add_around("*", "*");
		codeeditor.focus();
	});
	input_is_hidden.on('switchChange.bootstrapSwitch', function(e, state) {
		var ok = true;
		if (!state && !confirm("你确定要公开吗？")) {
			ok = false;
		}
		if (!ok) {
			input_is_hidden.bootstrapSwitch('toggleState', true);
		} else {
			input_is_hidden.bootstrapSwitch('readonly', true);
			var succ = true;
			save({
				fail: function() {
					succ = false;
				},
				done: function() {					
					input_is_hidden.bootstrapSwitch('readonly', false);
					if (!succ) {
						input_is_hidden.bootstrapSwitch('toggleState', true);
					}
				}
			});
		}
	});
	
	// init hot keys
	codeeditor.setOption("extraKeys", {
		"Ctrl-S": function(cm) {
			save_btn.click();
		},
		"Cmd-S": function(cm) {
			save_btn.click();
		},
		"Ctrl-B": function(cm) {
			bold_btn.click();
		},
		"Ctrl-D": function(cm) {
			preview_btn.click();
		},
		"Ctrl-I": function(cm) {
			italic_btn.click();
		}
	});
	$(document).bind('keydown', 'ctrl+d', function() {
		preview_btn.click();
		return false;
	});
	$.merge(input_title, input_tags).bind('keydown', 'ctrl+s', function() {
		save_btn.click();
		return false;
	}).bind('keydown', 'meta+s', function() {
		save_btn.click();
		return false;
	});
	
	// ---- what this browser kept from the last time
	saved_mark = mark(codeeditor.getValue());
	if (editor_config.draft_key) {
		var draft = uojDraft.read(editor_config.draft_key);
		var put_back = function() {
			input_title.val(draft.values.title);
			input_tags.val(draft.values.tags);
			codeeditor.setValue(draft.values.content_md);
			changed();
		};
		var usable = draft && typeof draft.values.title === 'string' && typeof draft.values.tags === 'string' && typeof draft.values.content_md === 'string';
		if (usable && same(draft.values, typed())) {
			// it was saved after all
			drop_draft();
		} else if (usable) {
			var saved = typed();
			var note = $('<div class="alert alert-info py-2" id="draft-note-' + name + '"></div>');
			var discard = $('<a href="#" class="alert-link ml-2" id="draft-discard-' + name + '"></a>').click(function(e) {
				e.preventDefault();
				drop_draft();
				input_title.val(saved.title);
				input_tags.val(saved.tags);
				codeeditor.setValue(saved.content_md);
				clearTimeout(autosave_timer);
				clearTimeout(draft_timer);
				set_saved(true);
				drop_draft();
				tell('');
				note.remove();
			});
			if (draft.values.base === saved_mark) {
				// nothing was saved since: the text goes on where it was left
				put_back();
				note.append($('<span></span>').text('已恢复 ' + uojDraft.when(draft) + ' 在这个浏览器里写了、但没有保存成功的修改' + (editor_config.autosave ? '，马上会自动保存。' : '，请记得保存。')))
					.append(discard.text('丢弃，回到已保存的版本'));
			} else {
				// Something else was saved since. Which of the two is wanted is for a person to
				// say: the older text does not write itself over the newer one.
				note.removeClass('alert-info').addClass('alert-warning')
					.append($('<span></span>').text('这个浏览器里留着 ' + uojDraft.when(draft) + ' 没有保存成功的一份修改，但在那之后内容又被保存过。现在显示的是已保存的版本。'))
					.append($('<a href="#" class="alert-link ml-2" id="draft-restore-' + name + '">换成那份修改</a>').click(function(e) {
						e.preventDefault();
						put_back();
						$(this).remove();
					}))
					.append(discard.text('丢弃那份修改'));
			}
			$(this_form).before(note);
		}
	}
	
	// the last words go to the server on the way out, and stay in the browser in case they do not arrive
	if (editor_config.autosave || editor_config.draft_key) {
		$(window).on('pagehide beforeunload', function() {
			if (is_saved) {
				return;
			}
			keep_draft();
			if (editor_config.autosave && !sent_on_the_way_out && navigator.sendBeacon && window.FormData) {
				sent_on_the_way_out = true;
				var last = new FormData();
				$($(this_form).serializeArray()).each(function() {
					last.append(this["name"], this["value"]);
				});
				last.append('autosave', 'on');
				last.append("save-" + name, '');
				navigator.sendBeacon(window.location.href, last);
			}
		});
	}
	
	if (this_form) {
		$(this_form).submit(function() {
			before_window_unload_message = null;
		});
	}
}
