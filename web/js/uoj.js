// locale
uojLocaleData = {
	"username": {
		"en": "Username",
		"zh-cn": "用户名"
	},
	"contests::total score": {
		"en": "Score",
		"zh-cn": "总分"
	},
	"contests::n participants": {
		"en": function(n) {
			return n + " participant" + (n <= 1 ? '' : 's');
		},
		"zh-cn": function(n) {
			return "共 " + n + " 名参赛者";
		}
	},
	"click-zan::good": {
		"en": "Good",
		"zh-cn": "好评"
	},
	"click-zan::bad": {
		"en": "Bad",
		"zh-cn": "差评"
	},
	"editor::use advanced editor": {
		"en": "use advanced editor",
		"zh-cn": "使用高级编辑器"
	},
	"editor::language": {
		"en": "Language",
		"zh-cn": "语言"
	},
	"editor::browse": {
		"en": "Browse",
		"zh-cn": "浏览"
	},
	"editor::upload by editor": {
		"en": "Upload by editor",
		"zh-cn": "使用编辑器上传"
	},
	"editor::upload from local": {
		"en": "Upload from local",
		"zh-cn": "从本地文件上传"
	},
	"sample::input": {
		"en": "Input",
		"zh-cn": "输入"
	},
	"sample::output": {
		"en": "Output",
		"zh-cn": "输出"
	},
	"sample::copy": {
		"en": "Copy",
		"zh-cn": "复制"
	},
	"sample::copied": {
		"en": "Copied",
		"zh-cn": "已复制"
	},
	"sample::not copied": {
		"en": "Select it and copy",
		"zh-cn": "请选中后复制"
	}
};

function uojLocale(name) {
	locale = $.cookie('uoj_locale');
	if (uojLocaleData[name] === undefined) {
		return '';
	}
	if (uojLocaleData[name][locale] === undefined) {
		locale = 'zh-cn';
	}
	val = uojLocaleData[name][locale];
	if (!$.isFunction(val)) {
		return val;
	} else {
		var args = [];
		for (var i = 1; i < arguments.length; i++) {
			args.push(arguments[i]);
		}
		return val.apply(this, args);
	}
}

// utility
function strToDate(str) {
	var a = str.split(/[^0-9]/);
	return new Date(
		parseInt(a[0]),
		parseInt(a[1]) - 1,
		parseInt(a[2]),
		parseInt(a[3]),
		parseInt(a[4]),
		parseInt(a[5]),
		0);
}
function dateToStr(date) {
	return date.getFullYear() + '-' + (date.getMonth() + 1) + '-' + date.getDate() + ' ' + date.getHours() + ':' + date.getMinutes() + ':' + date.getSeconds();
}
function toFilledStr(o, f, l) {
	var s = o.toString();
	while (s.length < l) {
		s = f.toString() + s;
	}
	return s;
}
function getPenaltyTimeStr(x) {
	var ss = toFilledStr(x % 60, '0', 2);
	x = Math.floor(x / 60);
	var mm = toFilledStr(x % 60, '0', 2);
	x = Math.floor(x / 60);
	var hh = x.toString();
	return hh + ':' + mm + ':' + ss;
}

function htmlspecialchars(str)
{
	var s = "";
	if (str.length == 0) return "";
	s = str.replace(/&/g, "&amp;");
	s = s.replace(/</g, "&lt;");
	s = s.replace(/>/g, "&gt;");
	s = s.replace(/"/g, "&quot;");
	return s;
}

function getColOfRating(rating) {
	if (rating < 1500) {
		var H = 300 - (1500 - 850) * 300 / 1650, S = 30 + (1500 - 850) * 70 / 1650, V = 50 + (1500 - 850) * 50 / 1650;
		if (rating < 300) rating = 300;
		var k = (rating - 300) / 1200;
		return ColorConverter.toStr(ColorConverter.toRGB(new HSV(H + (300 - H) * (1 - k), 30 + (S - 30) * k, 50 + (V - 50) * k)));
	}
	if (rating > 2500) {
		rating = 2500;
	}
	return ColorConverter.toStr(ColorConverter.toRGB(new HSV(300 - (rating - 850) * 300 / 1650, 30 + (rating - 850) * 70 / 1650, 50 + (rating - 850) * 50 / 1650)));
}
function getColOfScore(score) {
	if (score == 0) {
		return ColorConverter.toStr(ColorConverter.toRGB(new HSV(0, 100, 80)));
	} else if (score == 100) {
		return ColorConverter.toStr(ColorConverter.toRGB(new HSV(120, 100, 80)));
	} else {
		return ColorConverter.toStr(ColorConverter.toRGB(new HSV(30 + score * 60 / 100, 100, 90)));
	}
}

// a nickname never stands alone, it is followed by the username
function getUserText(username, alias) {
	if (alias && username.charAt(0) != '@') {
		return htmlspecialchars(String(alias)) + '（' + username + '）';
	}
	return username;
}

function getUserLink(username, rating, addSymbol, alias) {
	if (!username) {
		return '';
	}
	if (addSymbol == undefined) {
		addSymbol = true;
	}
	var text = getUserText(username, alias);
	if (username.charAt(0) == '@') {
		username = username.substr(1);
	}
	if (addSymbol) {
		if (rating >= 2500) {
			text += '<sup>';
			for (var i = 2500; i <= rating; i += 200) {
				text += "&alefsym;"
			}
			text += "</sup>";
		}
	}
	return '<a class="uoj-username" href="' + uojHome + '/user/profile/' + username + '" style="color:' + getColOfRating(rating) + '">' + text + '</a>';
}
function getUserSpan(username, rating, addSymbol, alias) {
	if (!username) {
		return '';
	}
	if (addSymbol == undefined) {
		addSymbol = true;
	}
	var text = getUserText(username, alias);
	if (username.charAt(0) == '@') {
		username = username.substr(1);
	}
	if (addSymbol) {
		if (rating >= 2500) {
			text += '<sup>';
			for (var i = 2500; i <= rating; i += 200) {
				text += "&alefsym;"
			}
			text += "</sup>";
		}
	}
	return '<span class="uoj-username" style="color:' + getColOfRating(rating) + '">' + text + '</span>';
}

function replaceWithHighlightUsername() {
	var username = $(this).text();
	var rating = $(this).data("rating");
	if (isNaN(rating)) {
		return;
	}
	// attr() and not data(), which would turn a nickname like "007" into a number
	var alias = $(this).attr("data-alias");
	if ($(this).data("link") != 0) {
		$(this).replaceWith(getUserLink(username, rating, undefined, alias));
	} else {
		$(this).replaceWith(getUserSpan(username, rating, undefined, alias));
	}
}

$.fn.uoj_honor = function() {
	return this.each(function() {
		var honor = $(this).text();
		var rating = $(this).data("rating");
		if (isNaN(rating)) {
			return;
		}
		if (rating >= 2500) {
			honor += '<sup>';
			for (var i = 2500; i <= rating; i += 200) {
				honor += "&alefsym;"
			}
			honor += "</sup>";
		}
		$(this).css("color", getColOfRating(rating)).html(honor);
	});
}

function showErrorHelp(name, err) {
	if (err) {
		$('#div-' + name).addClass('has-error');
		$('#help-' + name).text(err);
		return false;
	} else {
		$('#div-' + name).removeClass('has-error');
		$('#help-' + name).text('');
		return true;
	}
}
function getFormErrorAndShowHelp(name, val) {
	var err = val($('#input-' + name).val());
	return showErrorHelp(name, err);
}

function validateSettingPassword(str) {
	if (str.length < 6) {
		return '密码长度不应小于6。';
	} else if (! /^[!-~]+$/.test(str)) {
		return '密码应只包含可见ASCII字符。';
	} else if (str != $('#input-confirm_password').val()) {
		return '两次输入的密码不一致。';
	} else {
		return '';
	}
}
function validatePassword(str) {
	if (str.length < 6) {
		return '密码长度不应小于6。';
	} else if (! /^[!-~]+$/.test(str)) {
		return '密码应只包含可见ASCII字符。';
	} else {
		return '';
	}
}
function validateEmail(str) {
	if (str.length > 50) {
		return '电子邮箱地址太长。';
	} else if (! /^(.+)@(.+)$/.test(str)) {
		return '电子邮箱地址非法。';
	} else {
		return '';
	}
}
function validateUsername(str) {
	if (str.length == 0) {
		return '用户名不能为空。';
	} else if (! /^[a-zA-Z0-9_]+$/.test(str)) {
		return '用户名应只包含大小写英文字母、数字和下划线。';
	} else {
		return '';
	}
}
function validateQQ(str) {
	if (str.length < 5) {
		return 'QQ的长度不应小于5。';
	} else if (str.length > 15) {
		return 'QQ的长度不应大于15。';
	} else if (/\D/.test(str)) {
		return 'QQ应只包含0~9的数字。';
	} else {
		return '';
	}
}
function validateMotto(str) {
	if (str.length > 50) {
		return '不能超过50字';
	} else {
		return '';
	}
}

// tags
$.fn.uoj_problem_tag = function() {
	return this.each(function() {
		$(this).attr('href', uojHome + '/problems?tag=' + encodeURIComponent($(this).text()));
	});
}
$.fn.uoj_blog_tag = function() {
	return this.each(function() {
		$(this).attr('href', uojBlogUrl + '/archive?tag=' + encodeURIComponent($(this).text()));
	});
}

// click zan
function click_zan(zan_id, zan_type, zan_delta, node) {
	var loading_node = $('<div class="text-muted">loading...</div>');
	$(node).replaceWith(loading_node);
	$.post(zan_link + '/click-zan', {
		id : zan_id,
		delta : zan_delta,
		type : zan_type
	}, function(ret) {
		$(loading_node).replaceWith($(ret).click_zan_block());
	}).fail(function() {
		$(loading_node).replaceWith('<div class="text-danger">failed</div>');
	});
}

$.fn.click_zan_block = function() {
	return this.each(function() {
		var id = $(this).data('id');
		var type = $(this).data('type');
		var val = parseInt($(this).data('val'));
		var cnt = parseInt($(this).data('cnt'));
		if (isNaN(cnt)) {
			return;
		}
		if (val == 1) {
			$(this).addClass('uoj-click-zan-block-cur-up');
		} else if (val == 0) {
			$(this).addClass('uoj-click-zan-block-cur-zero');
		} else if (val == -1) {
			$(this).addClass('uoj-click-zan-block-cur-down');
		} else {
			return;
		}
		if (cnt > 0) {
			$(this).addClass('uoj-click-zan-block-positive');
		} else if (cnt == 0) {
			$(this).addClass('uoj-click-zan-block-neutral');
		} else {
			$(this).addClass('uoj-click-zan-block-negative');
		}
		
		var node = this;
		var up_node = $('<a href="#" class="uoj-click-zan-up"><span class="glyphicon glyphicon-thumbs-up"></span>'+uojLocale('click-zan::good')+'</a>').click(function(e) {
			e.preventDefault();
			click_zan(id, type, 1, node);
		});
		var down_node = $('<a href="#" class="uoj-click-zan-down"><span class="glyphicon glyphicon-thumbs-down"></span>'+uojLocale('click-zan::bad')+'</a>').click(function(e) {
			e.preventDefault();
			click_zan(id, type, -1, node);
		});
		
		$(this)
			.append(up_node)
			.append(down_node)
			.append($('<span class="uoj-click-zan-cnt">[<strong>' + (cnt > 0 ? '+' + cnt : cnt) + '</strong>]</span>'));
	});
}

// count down
function getCountdownStr(t) {
	var x = Math.floor(t);
	var ss = toFilledStr(x % 60, '0', 2);
	x = Math.floor(x / 60);
	var mm = toFilledStr(x % 60, '0', 2);
	x = Math.floor(x / 60);
	var hh = x.toString();
	
	var res = '<span style="font-size:30px">';
	res += '<span style="color:' + getColOfScore(Math.min(t / 10800 * 100, 100)) + '">' + hh + '</span>';
	res += ':';
	res += '<span style="color:' + getColOfScore(mm / 60 * 100) + '">' + mm + '</span>';
	res += ':';
	res += '<span style="color:' + getColOfScore(ss / 60 * 100) + '">' + ss + '</span>';
	res += '</span>'
	return res;
}

$.fn.countdown = function(rest, callback) {
	return this.each(function() {
		var start = new Date().getTime();
		var cur_rest = rest != undefined ? rest : parseInt($(this).data('rest'));
		var cur = this;
		var countdown = function() {
			var passed = Math.floor((new Date().getTime() - start) / 1000);
			if (passed >= cur_rest) {
				$(cur).html(getCountdownStr(0));
				if (callback != undefined) {
					callback();
				}
			} else {
				$(cur).html(getCountdownStr(cur_rest - passed));
				setTimeout(countdown, 1000);
			}
		}
		countdown();
	});
};

// update_judgement_status
update_judgement_status_list = []
function update_judgement_status_details(id) {
	update_judgement_status_list.push(id);
};

$(document).ready(function() {
	function update() {
		$.get("/submission-status-details", {
				get: update_judgement_status_list
			},
			function(data) {
				for (var i = 0; i < update_judgement_status_list.length; i++) {
					$("#status_details_" + update_judgement_status_list[i]).html(data[i].html);
					if (data[i].judged) {
						location.reload();
					}
				}
			}, 'json').always(
			function() {
    			setTimeout(update, 500);
	    	}
	    );
	}
	if (update_judgement_status_list.length > 0) {
		setTimeout(update, 500);
	}
});

// highlight
$.fn.uoj_highlight = function() {
	return $(this).each(function() {
		$(this).find("span.uoj-username").each(replaceWithHighlightUsername);
		$(this).find(".uoj-honor").uoj_honor();
		$(this).find(".uoj-score").each(function() {
			var score = parseInt($(this).text());
			var maxscore = parseInt($(this).data('max'));
			if (isNaN(score)) {
				return;
			}
			if (isNaN(maxscore)) {
				$(this).css("color", getColOfScore(score));
			} else {
				$(this).css("color", getColOfScore(score / maxscore * 100));
			}
		});
		$(this).find(".uoj-status").each(function() {
			var success = parseInt($(this).data("success"));
			if(isNaN(success)){
				return;
			}
			if (success == 1) {
				$(this).css("color", ColorConverter.toStr(ColorConverter.toRGB(new HSV(120, 100, 80))));
			}
			else {
				$(this).css("color", ColorConverter.toStr(ColorConverter.toRGB(new HSV(0, 100, 100))));
			}
		});
		$(this).find(".uoj-problem-tag").uoj_problem_tag();
		$(this).find(".uoj-blog-tag").uoj_blog_tag();
		$(this).find(".uoj-click-zan-block").click_zan_block();
		$(this).find(".countdown").countdown();
		$(this).find(".uoj-readmore").readmore({
			moreLink: '<a href="#" class="text-right">more...</a>',
			lessLink: '<a href="#" class="text-right">close</a>',
		});
	});
};

// Put a text where the reader pastes from, and say whether it got there. A page that is not
// reached over https is not given the clipboard of the browser, so the old way is kept.
function uojCopyText(text, done) {
	var old_way = function() {
		var field = $('<textarea readonly="readonly"></textarea>').val(text)
			.css({position: 'fixed', top: 0, left: 0, width: '1px', height: '1px', opacity: 0});
		$('body').append(field);
		field[0].select();
		field[0].setSelectionRange(0, text.length);
		var ok = false;
		try {
			ok = document.execCommand('copy');
		} catch (e) {
		}
		field.remove();
		done(ok);
	};
	if (navigator.clipboard && window.isSecureContext) {
		navigator.clipboard.writeText(text).then(function() {
			done(true);
		}, old_way);
	} else {
		old_way();
	}
}

// The samples of a statement. A statement written for Hydro names them where its blocks of
// code name their language:
//     ```input1        ```output1
// Such a block is a sample, not a program in a language called input1: it is shown under what
// it is, with a button that copies it, and an input stands beside its output where the page
// is wide enough for both.
$.fn.uoj_samples = function() {
	var kind_of = function(code) {
		var m = /(?:^|\s)(?:language|lang)-(input|output)(\d*)(?:\s|$)/i.exec(code.className || '');
		return m ? {kind: m[1].toLowerCase(), number: m[2]} : null;
	};
	var box_of = function(pre, what) {
		var code = pre.children('code');
		var button = $('<button type="button" class="btn btn-link btn-sm uoj-sample-copy"></button>').text(uojLocale('sample::copy'));
		button.click(function() {
			var text = code.text();
			uojCopyText(/\n$/.test(text) ? text : text + '\n', function(ok) {
				button.text(uojLocale(ok ? 'sample::copied' : 'sample::not copied'));
				setTimeout(function() {
					button.text(uojLocale('sample::copy'));
				}, 1500);
			});
		});
		var title = $('<div class="uoj-sample-title"></div>')
			.append($('<span></span>').text(uojLocale('sample::' + what.kind) + (what.number === '' ? '' : ' #' + what.number)))
			.append(button);
		// it is a text, whatever a highlighter would make of it
		code.attr('class', 'nohighlight').text(code.text());
		return $('<div class="uoj-sample"></div>').attr('data-kind', what.kind).attr('data-number', what.number).append(title);
	};
	return $(this).each(function() {
		$(this).find('pre > code').each(function() {
			var what = kind_of(this);
			var pre = $(this).parent();
			if (!what || pre.parent().hasClass('uoj-sample') || pre.children().length !== 1) {
				return;
			}
			var row = $('<div class="uoj-samples"></div>');
			var box = box_of(pre, what);
			pre.before(row);
			row.append(box.append(pre));
			// the output of this input, when it is what comes next
			var next = row.next('pre');
			var next_code = next.children('code');
			var next_what = next_code.length === 1 && next.children().length === 1 ? kind_of(next_code[0]) : null;
			if (what.kind === 'input' && next_what && next_what.kind === 'output' && next_what.number === what.number) {
				row.append(box_of(next, next_what).append(next));
			}
		});
	});
};

// ---- what is typed is kept in the browser until it is taken
//
// A long text is typed into a form, and then the page is left, the tab is closed or the
// login has run out: the text is kept in this browser under a name, and is there again when
// the form is opened again. It is thrown away when the server says that the form was taken.

var uojDraft = {
	name: function(key) {
		return 'uoj-draft:' + key;
	},
	// null where there is none, and where the browser keeps nothing
	read: function(key) {
		try {
			var draft = JSON.parse(window.localStorage.getItem(uojDraft.name(key)));
			return draft && typeof draft === 'object' && draft.values ? draft : null;
		} catch (e) {
			return null;
		}
	},
	write: function(key, values) {
		try {
			window.localStorage.setItem(uojDraft.name(key), JSON.stringify({saved: new Date().getTime(), values: values}));
			return true;
		} catch (e) {
			return false;
		}
	},
	remove: function(key) {
		try {
			window.localStorage.removeItem(uojDraft.name(key));
		} catch (e) {
		}
	},
	// "10:32", or "10月5日 10:32" when it was not today
	when: function(draft) {
		var at = new Date(draft.saved);
		var two = function(n) {
			return (n < 10 ? '0' : '') + n;
		};
		var time = two(at.getHours()) + ':' + two(at.getMinutes());
		return at.toDateString() === new Date().toDateString() ? time : (at.getMonth() + 1) + '月' + at.getDate() + '日 ' + time;
	}
};

// Keeps the fields with these names of a form. $note is where it is said that something was
// put back, with the way to throw it away.
$.fn.uoj_form_draft = function(key, names, $note) {
	var form = $(this);
	var field = function(name) {
		return form.find('[name="' + name + '"]');
	};
	var values = function() {
		var now = {};
		var any = false;
		$.each(names, function(i, name) {
			now[name] = field(name).val() || '';
			any = any || $.trim(now[name]) !== '';
		});
		return any ? now : null;
	};
	var keep = function() {
		var now = values();
		if (now) {
			uojDraft.write(key, now);
		} else {
			uojDraft.remove(key);
		}
	};
	// What the server filled in is newer than what the browser kept: the form was sent and
	// came back. The browser's copy is put back only into a form that is empty.
	var draft = uojDraft.read(key);
	if (draft && !values()) {
		$.each(names, function(i, name) {
			if (typeof draft.values[name] === 'string') {
				field(name).val(draft.values[name]).trigger('autosize.resize');
			}
		});
		if ($note) {
			$note.empty().append($('<span></span>').text('已恢复 ' + uojDraft.when(draft) + ' 写到一半、还没有提交的内容。'))
				.append($('<a href="#" class="alert-link ml-2" id="draft-discard">清空，重新写</a>').click(function(e) {
					e.preventDefault();
					$.each(names, function(i, name) {
						field(name).val('').trigger('autosize.resize');
					});
					uojDraft.remove(key);
					$note.hide();
				})).show();
		}
	}
	var timer = null;
	form.on('input change', function() {
		clearTimeout(timer);
		timer = setTimeout(keep, 400);
	});
	// what was typed in the last moment, and what is being sent now
	form.on('submit', keep);
	$(window).on('pagehide beforeunload', keep);
	return form;
};

// A form was taken: what the browser kept of it is of no use any more. The server says so
// with a cookie, and it is seen to before any form of the page looks for what was kept.
(function() {
	var done = $.cookie('uoj_draft_done');
	if (done) {
		uojDraft.remove(done);
		$.removeCookie('uoj_draft_done', {path: '/'});
	}
})();

$(document).ready(function() {
	$('body').uoj_samples();
	$('body').uoj_highlight();
	$('.uoj-problem-picker').uoj_problem_picker();
});

// A field that takes problems. What is typed, a number or a piece of a title, is looked up,
// and the problems that are chosen stand in the field as tags. The field itself goes on
// holding their numbers, as when they are typed, so the form is sent the way it always was;
// what is typed and not chosen is sent with them.
//   data-scope      'site', or the address name of the domain whose problems are meant
//   data-purpose    'manage' to be shown the problems one may put into a contest
//   data-multiple   several problems, in the order they were chosen
//   data-prefix     what a number is sent with in front of it
$.fn.uoj_problem_picker = function() {
	return this.each(function() {
		var field = $(this);
		if (field.data('uoj-picker')) {
			return;
		}
		field.data('uoj-picker', true);
		var multiple = field.attr('data-multiple') !== undefined;
		var chosen = [];
		var found = [];
		var active = -1;
		var asked = 0;
		var timer = null;
		// what the problems that are offered were looked up for, and whether Enter was
		// pressed before the answer to what is typed now was there
		var answered = null;
		var entered = false;

		var box = $('<div class="form-control uoj-picker"></div>');
		var input = $('<input type="text" class="uoj-picker-input" autocomplete="off" />').attr('placeholder', field.attr('placeholder') || '');
		var menu = $('<div class="dropdown-menu uoj-picker-menu"></div>');
		if (field[0].style.width) {
			box.css('min-width', field[0].style.width);
		}
		if (field.attr('id')) {
			input.attr('id', field.attr('id') + '-search');
			box.attr('id', field.attr('id') + '-picker');
		}
		box.append(input).append(menu);
		// a field nobody sees can not be asked for by the browser
		field.removeAttr('required').removeAttr('pattern').hide().after(box);

		var scope = function() {
			return field.attr('data-scope') || 'site';
		};
		var isChosen = function(number) {
			for (var i = 0; i < chosen.length; i++) {
				if (chosen[i].number == number) {
					return i;
				}
			}
			return -1;
		};
		var sync = function() {
			var prefix = field.attr('data-prefix') || '';
			var typed = $.trim(input.val());
			var parts = [];
			if (multiple || typed === '') {
				for (var i = 0; i < chosen.length; i++) {
					parts.push(prefix + chosen[i].number);
				}
			}
			if (typed !== '') {
				parts.push(typed);
			}
			field.val(parts.join(' ')).trigger('change');
		};
		var showChosen = function() {
			box.children('.uoj-picker-chip').remove();
			$.each(chosen, function(i, problem) {
				var chip = $('<span class="uoj-picker-chip"></span>').attr('data-number', problem.number);
				chip.append($('<span class="uoj-picker-number"></span>').text(problem.number));
				chip.append($('<span class="uoj-picker-title"></span>').text(problem.title));
				chip.append($('<a href="#" class="uoj-picker-remove" title="去掉">&times;</a>').click(function(e) {
					e.preventDefault();
					chosen.splice(isChosen(problem.number), 1);
					showChosen();
					input.focus();
				}));
				chip.insertBefore(input);
			});
			sync();
		};
		var closeMenu = function() {
			menu.removeClass('show');
			active = -1;
		};
		var choose = function(problem) {
			var at = isChosen(problem.number);
			if (at >= 0) {
				chosen.splice(at, 1);
			} else if (multiple) {
				chosen.push(problem);
			} else {
				chosen = [problem];
			}
			input.val('');
			entered = false;
			closeMenu();
			showChosen();
		};
		var showMenu = function() {
			menu.empty();
			if (!found.length) {
				menu.append($('<span class="dropdown-item-text text-muted small"></span>').text($.trim(input.val()) === '' ? '这里还没有可以选的题目' : '没有找到这样的题目'));
			}
			$.each(found, function(i, problem) {
				var item = $('<a href="#" class="dropdown-item"></a>').attr('data-number', problem.number);
				item.append($('<span class="uoj-picker-number"></span>').text('#' + problem.number));
				item.append($('<span></span>').text(problem.title));
				if (problem.hidden) {
					item.append(' <span class="badge badge-secondary">隐藏</span>');
				}
				// the tags that have what was typed in them
				$.each(problem.tags || [], function(j, tag) {
					item.append(' ').append($('<span class="badge badge-pill badge-light border uoj-picker-tag"></span>').text(tag));
				});
				if (isChosen(problem.number) >= 0) {
					item.append(' <span class="glyphicon glyphicon-ok text-success"></span>');
				}
				if (i === active) {
					item.addClass('active');
				}
				// before the field loses the cursor to the click
				item.on('mousedown', function(e) {
					e.preventDefault();
				}).on('click', function(e) {
					e.preventDefault();
					choose(problem);
				});
				menu.append(item);
			});
			menu.addClass('show');
		};
		var lookUp = function() {
			var mine = ++asked;
			var typed = input.val();
			$.getJSON(uojHome + '/problems/pick', {scope: scope(), purpose: field.attr('data-purpose') || '', q: typed}, function(answer) {
				if (mine !== asked || !input.is(':focus')) {
					return;
				}
				found = answer.problems || [];
				answered = typed;
				active = found.length && $.trim(typed) !== '' ? 0 : -1;
				// Enter that was pressed too early waits for the answer to what is typed now,
				// not for one that was on its way before
				if (entered && typed === input.val()) {
					entered = false;
					if (active >= 0) {
						choose(found[active]);
						return;
					}
				}
				showMenu();
			});
		};

		box.on('click', function(e) {
			if (e.target === box[0]) {
				input.focus();
			}
		});
		input.on('focus', function() {
			box.addClass('focus');
			lookUp();
		}).on('blur', function() {
			box.removeClass('focus');
			asked++;
			entered = false;
			closeMenu();
			sync();
		}).on('input', function() {
			clearTimeout(timer);
			timer = setTimeout(lookUp, 150);
			entered = false;
			sync();
		}).on('keydown', function(e) {
			if (e.which === 40 || e.which === 38) {
				if (menu.hasClass('show') && found.length) {
					active = (active + (e.which === 40 ? 1 : found.length - 1) + (active < 0 && e.which === 38 ? 1 : 0)) % found.length;
					showMenu();
					var item = menu.children('.active')[0];
					if (item && item.scrollIntoView) {
						item.scrollIntoView({block: 'nearest'});
					}
				}
				e.preventDefault();
			} else if (e.which === 13) {
				if ($.trim(input.val()) !== '' && answered !== input.val()) {
					// typed faster than it was looked up: it is chosen when the answer is there
					e.preventDefault();
					entered = true;
				} else if (menu.hasClass('show') && active >= 0 && found[active]) {
					e.preventDefault();
					choose(found[active]);
				}
			} else if (e.which === 27) {
				closeMenu();
			} else if (e.which === 8 && input.val() === '' && chosen.length) {
				chosen.pop();
				showChosen();
			}
		});
		field.closest('form').on('submit', sync);
		// another place to look in: what was chosen was chosen from the other place
		field.on('uoj-picker-scope', function(e, newScope, prefix) {
			field.attr('data-scope', newScope).attr('data-prefix', prefix || '');
			chosen = [];
			input.val('');
			showChosen();
		});

		// the numbers the field came with are the problems that are chosen
		var numbers = $.trim(field.val());
		if (numbers !== '' && /^[0-9#\s,，;；、]+$/.test(numbers)) {
			$.getJSON(uojHome + '/problems/pick', {scope: scope(), purpose: field.attr('data-purpose') || '', numbers: numbers}, function(answer) {
				var known = {};
				$.each(answer.problems || [], function(i, problem) {
					known[problem.number] = problem;
				});
				var left = [];
				$.each(numbers.split(/[^0-9]+/), function(i, number) {
					if (number === '') {
						return;
					}
					if (known[parseInt(number, 10)] && isChosen(parseInt(number, 10)) < 0 && (multiple || !chosen.length)) {
						chosen.push(known[parseInt(number, 10)]);
					} else {
						left.push(number);
					}
				});
				input.val(left.join(' '));
				showChosen();
			});
		} else {
			input.val(numbers);
		}
	});
};

// contest notice
function checkContestNotice(id, lastTime) {
	$.post('/contest/' + id.toString(), {
			check_notice : '',
			last_time : lastTime
		},
		function(data) {
			setTimeout(function() {
				checkContestNotice(id, data.time);
			}, 60000);
			if (data.msg != undefined) {
				var len=data.msg.length;
				for (var i=0;i<len;i++) alert(data.msg[i]);
			}
		},
		'json'
	).fail(function() {
		setTimeout(function() {
			checkContestNotice(id, lastTime);
		}, 60000);
	});
}

// long table
$.fn.long_table = function(data, cur_page, header_row, get_row_str, config) {
	return this.each(function() {
		var table_div = this;
		
		$(table_div).html('');
		
		var page_len = config.page_len != undefined ? config.page_len : 10;
		
		if (!config.echo_full) {
			var n_rows = data.length;
			var n_pages = Math.max(Math.ceil(n_rows / page_len), 1);
			if (cur_page == undefined) {
				cur_page = 1;
			}
			if (cur_page < 1) {
				cur_page = 1;
			} else if (cur_page > n_pages) {
				cur_page = n_pages;
			}
			var cur_start = (cur_page - 1) * page_len;
		} else {
			var n_rows = data.length;
			var n_pages = 1;
			cur_page = 1;
			var cur_start = (cur_page - 1) * page_len;
		}
		
		var div_classes = config.div_classes != undefined ? config.div_classes : ['table-responsive'];
		var table_classes = config.table_classes != undefined ? config.table_classes : ['table', 'table-bordered', 'table-hover', 'table-striped', 'table-text-center'];
		
		var now_cnt = 0;
		var tbody = $('<tbody />')
		for (var i = 0; i < page_len && cur_start + i < n_rows; i++) {
			now_cnt++;
			if (config.get_row_index) {
				tbody.append(get_row_str(data[cur_start + i], cur_start + i));
			} else {
				tbody.append(get_row_str(data[cur_start + i]));
			}
		}
		if (now_cnt == 0) {
			tbody.append('<tr><td colspan="233">无</td></tr>');
		}
		
		$(table_div).append(
			$('<div class="' + div_classes.join(' ') + '" />').append(
				$('<table class="' + table_classes.join(' ') + '" />').append(
					$('<thead>' + header_row + '</thead>')
				).append(
					tbody
				)
			)
		);
		
		if (config.print_after_table != undefined) {
			$(table_div).append(config.print_after_table());
		}
		
		var get_page_li = function(p, h) {
			if (p == -1) {
				return $('<li class="page-item"></li>').addClass('disabled').append($('<a class="page-link"></a>').append(h));
			}
			
			var li = $('<li class="page-item"></li>');
			if (p == cur_page) {
				li.addClass('active');
			}
			li.append(
				$('<a class="page-link"></a>').attr('href', '#' + table_div.id).append(h).click(function(e) {
					if (config.prevent_focus_on_click) {
						e.preventDefault();
					}
					$(table_div).long_table(data, p, header_row, get_row_str, config);
				})
			);
			return li;
		};
		
		if (n_pages > 1) {
			var pagination = $('<ul class="pagination top-buffer-no bot-buffer-sm justify-content-center"></ul>');
			if (cur_page > 1) {
				pagination.append(get_page_li(cur_page - 1, '<span class="glyphicon glyphicon glyphicon-backward"></span>'));
			} else {
				pagination.append(get_page_li(-1, '<span class="glyphicon glyphicon glyphicon-backward"></span>'));
			}
			var max_extend = config.max_extend != undefined ? config.max_extend : 5;
			for (var i = Math.max(cur_page - max_extend, 1); i <= Math.min(cur_page + max_extend, n_pages); i++) {
				pagination.append(get_page_li(i, i.toString()));
			}
			if (cur_page < n_pages) {
				pagination.append(get_page_li(cur_page + 1, '<span class="glyphicon glyphicon glyphicon-forward"></span>'));
			} else {
				pagination.append(get_page_li(-1, '<span class="glyphicon glyphicon glyphicon-forward"></span>'));
			}
			$(table_div).append($('<div class="text-center"></div>').append(pagination));
		}
	});
};

// code mirror
function require_codemirror(config, callback) {
	if ($('link[href="' + uojHome + '/js/codemirror/lib/codemirror.css' + '"]').length == 0) {
		$('<link type="text/css" rel="stylesheet" href="' + uojHome + '/js/codemirror/lib/codemirror.css' + '" />').appendTo('head');
	}
	$LAB.script(uojHome + '/js/codemirror/lib/codemirror.js')
		.wait()
		.script(uojHome + '/js/codemirror/addon/mode/overlay.js')
		.script(uojHome + '/js/codemirror/addon/selection/active-line.js')
		.wait(callback)
}

function get_codemirror_mode(lang) {
	switch (lang) {
		case 'C++':
		case 'C++98':
		case 'C++03':
		case 'C++11':
		case 'C++14':
		case 'C++17':
		case 'C++20':
		case 'C++23':
		case 'C++26':
			return 'text/x-c++src';
		case 'C':
		case 'C89':
		case 'C99':
		case 'C11':
		case 'C17':
		case 'C23':
			return 'text/x-csrc';
		case 'Python2':
		case 'Python3':
			return 'text/x-python';
		case 'Java8':
		case 'Java11':
		case 'Java17':
		case 'Java21':
			return 'text/x-java';
		case 'Pascal':
			return 'text/x-pascal';
		case 'text':
			return 'text/plain';
		default:
			return 'text/plain';
	}
};
function require_codemirror_mode(mode, callback) {
	var name = 'none';
	switch (mode) {
		case 'text/x-c++src':
		case 'text/x-csrc':
		case 'text/x-java':
			name = 'clike';
			break;
		case 'text/x-python':
			name = 'python';
			break;
		case 'text/x-pascal':
			name = 'pascal';
			break;
	}
	if (name !== 'none') {
		$LAB.script(uojHome + '/js/codemirror/mode/' + name + '/' + name + '.js')
			.wait(callback);
	} else {
		setTimeout(callback, 0);
	}
};

// auto save
function autosave_locally(interval, name, target) {
	if (typeof(Storage) === "undefined") {
		console.log('autosave_locally: Sorry! No Web Storage support..');
		return;
	}
	var url = window.location.href;
	var hp = url.indexOf('#');
	var uri = hp == -1 ? url : url.substr(0, hp);
	var full_name = name + '@' + uri;

	target.val(localStorage.getItem(full_name));
	var save = function() {
		localStorage.setItem(full_name, target.val());
		setTimeout(save, interval);
	};
	setTimeout(save, interval);
}

// source code form group
$.fn.source_code_form_group = function(name, text, langs_options_html) {
	return this.each(function() {
		var input_language_id = 'input-' + name + '_language';
		var input_language_name = name + '_language';
		var input_upload_type_name = name + '_upload_type';
		var input_editor_id = 'input-' + name + '_editor';
		var input_editor_name = name + '_editor';
		var input_file_id = 'input-' + name + '_file';
		var input_file_name = name + '_file';

		var div_help_language_id = 'div-help-' + name + '_language';
		var div_editor_id = 'div-' + name + '_editor';
		var div_file_id = 'div-' + name + '_file';

		var help_file_id = 'help-' + name + '_file';

		var input_language =
			$('<select id="' + input_language_id + '" name="' + input_language_name + '" class="form-control input-sm"/>')
				.html(langs_options_html);
		var input_upload_type_editor = $('<input type="radio" name="' + input_upload_type_name + '" value="editor" />');
		var input_upload_type_file = $('<input type="radio" name="' + input_upload_type_name + '" value="file" />');
		var input_file = $('<input type="file" id="' + input_file_id + '" name="' + input_file_name + '" style="display: none" />');
		var input_file_path = $('<input class="form-control" type="text" readonly="readonly" />');
		var input_editor = $('<textarea class="form-control" id="' + input_editor_id + '" name="' + input_editor_name + '"></textarea>');
		var input_use_advanced_editor = $('<input type="checkbox">');

		var div_editor =
			$('<div id="' + div_editor_id + '" class="col-sm-12"/>')
				.append(input_editor)
				.append($('<div class="checkbox text-right" />')
					.append($('<label />')
						.append(input_use_advanced_editor)
						.append(' ' + uojLocale('editor::use advanced editor'))
					)
				)
		var div_file =
			$('<div id="' + div_file_id + '" class="col-sm-12"/>')
				.append(input_file)
				.append($('<div class="input-group"/>')
					.append(input_file_path)
					.append($('<span class="input-group-append"/>')
						.append($('<button type="button" class="btn btn-primary">'+'<span class="glyphicon glyphicon-folder-open"></span> '+uojLocale('editor::browse')+'</button>')
							.css('width', '100px')
							.click(function() {
								input_file.click();
							})
						)
					)
				)
				.append($('<span class="help-block" id="' + help_file_id + '"></span>'))
		var div_help_language = $('<div id="' + div_help_language_id + '" class="col-sm-12 text-warning top-buffer-sm">');

		var show_help_lang = function() {
			if ($(this).val() == 'Java8' || $(this).val() == 'Java11' || $(this).val() == 'Java17' || $(this).val() == 'Java21') {
				div_help_language.text('注意：Java 程序源代码中不应指定所在的 package。我们会在源代码中找到第一个被定义的类并以它的 main 函数为程序入口点。');
			} else {
				div_help_language.text('');
			}
		};

		var advanced_editor = null;
		var advanced_editor_init = function() {
			require_codemirror({}, function() {
				var mode = get_codemirror_mode(input_language.val());
				require_codemirror_mode(mode, function() {
					if (advanced_editor != null) {
						return;
					}
					advanced_editor = CodeMirror.fromTextArea(input_editor[0], {
						mode: mode,
						lineNumbers: true,
						matchBrackets: true,
						lineWrapping: true,
						styleActiveLine: true,
						indentUnit: 4,
						indentWithTabs: true,
						theme: 'default'
					});
					advanced_editor.on('change', function() {
						advanced_editor.save();
					});
					$(advanced_editor.getWrapperElement()).css('box-shadow', '0 2px 10px rgba(0,0,0,0.2)');
					advanced_editor.focus();
				});
			});
		}

		var save_prefer_upload_type = function(type) {
			$.cookie('uoj_source_code_form_group_preferred_upload_type', type, { expires: 7, path: '/' });
		};

		autosave_locally(2000, name, input_editor);

		var prefer_upload_type = $.cookie('uoj_source_code_form_group_preferred_upload_type');
		if (prefer_upload_type === null) {
			prefer_upload_type = 'editor';
		}
		if (prefer_upload_type == 'file') {
			input_upload_type_file[0].checked = true;
			div_editor.css('display', 'none');
		} else {
			input_upload_type_editor[0].checked = true;
			div_file.css('display', 'none');

			if (prefer_upload_type == 'advanced') {
				input_use_advanced_editor[0].checked = true;
			}
		}

		input_language.each(show_help_lang);
		input_language.change(show_help_lang);
		input_language.change(function() {
			if (advanced_editor != null) {
				var mode = get_codemirror_mode(input_language.val());
				require_codemirror_mode(mode, function() {
					if (mode != get_codemirror_mode(input_language.val())) {
						return;
					}
					advanced_editor.setOption('mode', mode);
				});
			}
		})
		input_upload_type_editor.click(function() {
			div_editor.show('fast');
			div_file.hide('fast');
			save_prefer_upload_type('editor');
		});
		input_upload_type_file.click(function() {
			div_file.show('fast');
			div_editor.hide('fast');
			save_prefer_upload_type('file');
		});
		input_file.change(function() {
			input_file_path.val(input_file.val());
		});
		input_use_advanced_editor.click(function() {
			if (this.checked) {
				advanced_editor_init();
				save_prefer_upload_type('advanced');
			} else {
				if (advanced_editor != null) {
					advanced_editor.toTextArea();
					advanced_editor = null;
					input_editor.focus();
				}
				save_prefer_upload_type('editor');
			}
		});

		$(this)
			.append($('<div class="row col-sm-12"/>')
			.append($('<label class="col-sm-2 control-label"><div class="text-left">' + text + '</div></label>'))
			.append($('<label class="col-sm-1 control-label" for="' + input_language_name + '">'+uojLocale('editor::language')+'</label>'))
			.append($('<div class="col-sm-2"/>')
				.append(input_language)
			)
			.append($('<div class="col-sm-2 offset-sm-3 radio"/>')
				.append($('<label/>')
					.append(input_upload_type_editor)
					.append(' '+uojLocale('editor::upload by editor'))
				)
			)
			.append($('<div class="col-sm-2 radio"/>')
				.append($('<label/>')
					.append(input_upload_type_file)
					.append(' '+uojLocale('editor::upload from local'))
				)
			))
			.append(div_help_language)
			.append(div_editor)
			.append(div_file);

		if (prefer_upload_type == 'advanced') {
			var check_advanced_init = function() {
				if (div_editor.is(':visible')) {
					advanced_editor_init();
				} else {
					setTimeout(check_advanced_init, 1);
				}
			}
			check_advanced_init();
		}
	});
}

// text file form group
$.fn.text_file_form_group = function(name, text) {
	return this.each(function() {
		var input_upload_type_name = name + '_upload_type';
		var input_editor_id = 'input-' + name + '_editor';
		var input_editor_name = name + '_editor';
		var input_file_id = 'input-' + name + '_file';
		var input_file_name = name + '_file';

		var div_editor_id = 'div-' + name + '_editor';
		var div_file_id = 'div-' + name + '_file';

		var help_file_id = 'help-' + name + '_file';

		var input_upload_type_editor = $('<input type="radio" name="' + input_upload_type_name + '" value="editor" />');
		var input_upload_type_file = $('<input type="radio" name="' + input_upload_type_name + '" value="file" />');
		var input_file = $('<input type="file" id="' + input_file_id + '" name="' + input_file_name + '" style="display: none" />');
		var input_file_path = $('<input class="form-control" type="text" readonly="readonly" />');
		var input_editor = $('<textarea class="form-control" id="' + input_editor_id + '" name="' + input_editor_name + '"></textarea>');
		var input_use_advanced_editor = $('<input type="checkbox">');

		var div_editor =
			$('<div id="' + div_editor_id + '" class="col-sm-12"/>')
				.append(input_editor)
				.append($('<div class="checkbox text-right" />')
					.append($('<label />')
						.append(input_use_advanced_editor)
						.append(' ' + uojLocale('editor::use advanced editor'))
					)
				)
		var div_file =
			$('<div id="' + div_file_id + '" class="col-sm-12"/>')
				.append(input_file)
				.append($('<div class="input-group"/>')
					.append(input_file_path)
					.append($('<div class="input-group-append"/>')
						.append($('<button type="button" class="btn btn-primary">'+'<span class="glyphicon glyphicon-folder-open"></span> '+uojLocale('editor::browse')+'</button>')
							.css('width', '100px')
							.click(function() {
								input_file.click();
							})
						)
					)
				)
				.append($('<span class="help-block" id="' + help_file_id + '"></span>'))

		var advanced_editor = null;
		var advanced_editor_init = function() {
			require_codemirror({}, function() {
				var mode = get_codemirror_mode('text');
				require_codemirror_mode(mode, function() {
					if (advanced_editor != null) {
						return;
					}
					advanced_editor = CodeMirror.fromTextArea(input_editor[0], {
						mode: mode,
						lineNumbers: true,
						matchBrackets: true,
						lineWrapping: true,
						styleActiveLine: true,
						indentUnit: 4,
						indentWithTabs: true,
						theme: 'default'
					});
					advanced_editor.on('change', function() {
						advanced_editor.save();
					});
					$(advanced_editor.getWrapperElement()).css('box-shadow', '0 2px 10px rgba(0,0,0,0.2)');
					advanced_editor.focus();
				});
			});
		}

		var save_prefer_upload_type = function(type) {
			$.cookie('uoj_text_file_form_group_preferred_upload_type', type, { expires: 7, path: '/' });
		};

		autosave_locally(2000, name, input_editor);

		var prefer_upload_type = $.cookie('uoj_text_file_form_group_preferred_upload_type');
		if (prefer_upload_type === null) {
			prefer_upload_type = 'editor';
		}
		if (prefer_upload_type == 'file') {
			input_upload_type_file[0].checked = true;
			div_editor.css('display', 'none');
		} else {
			input_upload_type_editor[0].checked = true;
			div_file.css('display', 'none');

			if (prefer_upload_type == 'advanced') {
				input_use_advanced_editor[0].checked = true;
			}
		}

		input_upload_type_editor.click(function() {
			div_editor.show('fast');
			div_file.hide('fast');
			save_prefer_upload_type('editor');
		});
		input_upload_type_file.click(function() {
			div_file.show('fast');
			div_editor.hide('fast');
			save_prefer_upload_type('file');
		});
		input_file.change(function() {
			input_file_path.val(input_file.val());
		});
		input_use_advanced_editor.click(function() {
			if (this.checked) {
				advanced_editor_init();
				save_prefer_upload_type('advanced');
			} else {
				if (advanced_editor != null) {
					advanced_editor.toTextArea();
					advanced_editor = null;
					input_editor.focus();
				}
				save_prefer_upload_type('editor');
			}
		});

		$(this)
			.append($('<div class="row"/>')
			.append($('<label class="col-sm-2 control-label"><div class="text-left">' + text + '</div></label>'))
			.append($('<div class="top-buffer-sm" />'))
			.append($('<div class="col-sm-2 offset-sm-6 radio"/>')
				.append($('<label/>')
					.append(input_upload_type_editor)
					.append(' '+uojLocale('editor::upload by editor'))
				)
			)
			.append($('<div class="col-sm-2 radio"/>')
				.append($('<label/>')
					.append(input_upload_type_file)
					.append(' '+uojLocale('editor::upload from local'))
				)
			))
			.append(div_editor)
			.append(div_file);

		if (prefer_upload_type == 'advanced') {
			var check_advanced_init = function() {
				if (div_editor.is(':visible')) {
					advanced_editor_init();
				} else {
					setTimeout(check_advanced_init, 1);
				}
			}
			check_advanced_init();
		}
	});
}

// custom test
function custom_test_onsubmit(response_text, div_result, url) {
	if (response_text != '') {
		$(div_result).html('<div class="text-danger">' + response_text + '</div>');
		return;
	}
	var update = function() {
		var can_next = true;
		$.get(url,
			function(data) {
				if (data.judged === undefined) {
					$(div_result).html('<div class="text-danger">error</div>');
				} else {
					var judge_status = $('<table class="table table-bordered table-text-center"><tr class="info">' + data.html + '</tr></table>');
					$(div_result).empty();
					$(div_result).append(judge_status);
					if (data.judged) {
						var judge_result = $(data.result);
						judge_result.css('display', 'none');
						$(div_result).append(judge_result);
						judge_status.hide(500);
						judge_result.slideDown(500);
						can_next = false;
					}
				}
			}, 'json')
		.always(function() {
			if (can_next) {
				setTimeout(update, 500);
			}
		});
	};
	setTimeout(update, 500);
}

// comment
function showCommentReplies(id, replies) {
	var toggleFormReply = function(from, text) {
		if (text == undefined) {
			text = '';
		}
		
		var p = '#comment-body-' + id;
		var q = '#div-form-reply';
		var r = '#input-reply_comment';
		var t = '#input-reply_id';
		if ($(q).data('from') != from) {
			$(q).data('from', from);
			$(q).hide('fast', function() {
				$(this).appendTo(p).show('fast', function() {
					$(t).val(id);
					$(r).val(text).focus();
				});
			});

		} else if ($(q).css('display') != 'none') {
			$(q).appendTo(p).hide('fast');
		} else {
			$(q).appendTo(p).show('fast', function() {
				$(t).val(id);
				$(r).val(text).focus();
			});
		}
	}

	$('#reply-to-' + id).click(function(e) {
		e.preventDefault();
		toggleFormReply(id);
	});
	
	if (replies.length == 0) {
		return;
	}
	
	$("#replies-" + id).long_table(
		replies,
		1,
		'<tr>' +
			'<th>评论回复</th>' +
		'</tr>',
		function(reply) {
			return $('<tr id="' + 'comment-' + reply.id + '" />').append(
				$('<td />').append(
					$('<div class="comtbox6">' + getUserLink(reply.poster, reply.poster_rating) + '：' + reply.content + '</div>')
				).append(
					$('<ul class="text-right list-inline bot-buffer-no" />').append(
						'<li>' + '<small class="text-muted">' + reply.post_time + '</small>' + '</li>'
					).append(
						$('<li />').append(
							$('<a href="#">回复</a>').click(function (e) {
								e.preventDefault();
								toggleFormReply(reply.id, '回复 @' + reply.poster + '：');
							})
						)
					)
				)
			).uoj_highlight();
		}, {
			table_classes: ['table', 'table-condensed'],
			page_len: 5,
			prevent_focus_on_click: true
		}
	);
}

// standings
function showStandings() {
	$("#standings").long_table(
		standings,
		1,
		'<tr>' +
			'<th style="width:5em">#</th>' +
			'<th style="width:14em">'+uojLocale('username')+'</th>' +
			'<th style="width:5em">'+uojLocale('contests::total score')+'</th>' +
			$.map(problems, function(col, idx) {
				// a problem of a contest is called by its letter, in its address as well
				var letter = String.fromCharCode('A'.charCodeAt(0) + idx);
				return '<th style="width:8em;">' + '<a href="/contest/' + contest_id + '/problem/' + letter + '">' + letter + '</a>' + '</th>';
			}).join('') +
		'</tr>',
		function(row) {
			// Somebody who sat the contest virtually afterwards stands where they would have
			// stood, with the rank they would have had: their cells are kept under "v/" and
			// their name, since they may have taken part as well.
			var virtual = row[2][3] === 'v';
			var cells = score[(virtual ? 'v/' : '') + row[2][0]] || {};
			var col_tr = virtual ? '<tr class="uoj-standings-virtual" data-virtual="' + row[2][0] + '">' : '<tr>';
			col_tr += '<td>' + (virtual ? '<span class="text-muted" title="赛后虚拟参赛：放在正式比赛里是第 ' + row[3] + ' 名">(' + row[3] + ')</span>' : row[3]) + '</td>';
			col_tr += '<td>' + getUserLink(row[2][0], row[2][1], undefined, row[2][2]) + (virtual ? ' <span class="badge badge-info">虚拟</span>' : '') + '</td>';
			col_tr += '<td>' + '<div><span class="uoj-score" data-max="' + problems.length * 100 + '" style="color:' + getColOfScore(row[0] / problems.length) + '">' + row[0] + '</span></div>' + '<div>' + getPenaltyTimeStr(row[1]) + '</div></td>';
			for (var i = 0; i < problems.length; i++) {
				col_tr += '<td>';
				col = cells[i];
				if (col != undefined) {
					col_tr += '<div><a href="/submission/' + col[2] + '" class="uoj-score" style="color:' + getColOfScore(col[0]) + '">' + col[0] + '</a></div>';
					if (standings_version < 2) {
						col_tr += '<div>' + getPenaltyTimeStr(col[1]) + '</div>';
					} else {
						if (col[0] > 0) {
							col_tr += '<div>' + getPenaltyTimeStr(col[1]) + '</div>';
						}
					}
				}
				col_tr += '</td>';
			}
			col_tr += '</tr>';
			return col_tr;
		}, {
			table_classes: ['table', 'table-bordered', 'table-striped', 'table-text-center', 'table-vertical-middle', 'table-condensed'],
			page_len: 100,
			print_after_table: function() {
				var contestants = $.grep(standings, function(row) {
					return row[2][3] !== 'v';
				}).length;
				return '<div class="text-right text-muted">' + uojLocale("contests::n participants", contestants) + '</div>';
			}
		}
	);
}
