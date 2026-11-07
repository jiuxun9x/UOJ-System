// The outer board of a contest (contest_board.php): drawn here from what the page hands over,
// and kept up to date. The board is asked for again every few seconds; when something
// happened, the rows move to where they belong and what changed lights up, so that somebody
// who watches sees it happen.
(function($) {
	var root = $('#outer-board');
	if (!root.length || !window.uojOuterBoard) {
		return;
	}
	var state = window.uojOuterBoard;
	var me = String(root.data('me') || '');
	var url = root.data('url');
	var here = String(root.data('here') || '');
	// when what the state says of the clock was true
	var told = Date.now();
	var failures = 0;
	var timer = null;

	var esc = function(text) {
		return $('<i />').text(text === null || text === undefined ? '' : String(text)).html();
	};
	var two = function(n) {
		return (n < 10 ? '0' : '') + n;
	};
	var clock = function(seconds) {
		seconds = Math.max(0, Math.floor(seconds));
		return Math.floor(seconds / 3600) + ':' + two(Math.floor(seconds / 60) % 60) + ':' + two(seconds % 60);
	};
	var icpc = function() {
		return state.rule === 'ICPC';
	};
	// whose row is lit up: in somebody's view of the board theirs, else the viewer's own
	var whose = function() {
		return state.viewing ? state.viewing.key : me;
	};
	// the page of the board, as somebody who sat the contest virtually sees it ('' for nobody)
	var viewOf = function(username) {
		return here + (username ? '?vp=' + encodeURIComponent(username) : '');
	};
	var keep = function(name, value) {
		try {
			if (value === undefined) {
				return window.localStorage.getItem('uoj-outer-board-' + name);
			}
			window.localStorage.setItem('uoj-outer-board-' + name, value);
		} catch (e) {
			// a browser that keeps nothing forgets the choice, and nothing else
		}
		return null;
	};

	// ---- the table
	var head = function() {
		var html = '<tr><th class="uoj-ob-rank">排名</th><th class="uoj-ob-name">选手</th>';
		$.each(state.problems, function(pos, problem) {
			// where the contest gives balloons, a problem is headed by the colour of its balloon
			var balloon = problem.color ? ' style="box-shadow: inset 0 -5px 0 ' + esc(problem.color) + '"' : '';
			html += '<th class="uoj-ob-problem ' + (problem.solved > 0 ? 'uoj-ob-solved-by' : 'uoj-ob-unsolved') + '" data-problem="' + esc(problem.letter) + '"'
				+ ' data-solved="' + problem.solved + '" data-tries="' + problem.tries + '"' + balloon + '>'
				+ '<span>' + esc(problem.letter) + '</span><small>' + problem.solved + '/' + problem.tries + '</small></th>';
		});
		html += '<th class="uoj-ob-num">' + (icpc() ? '通过' : '总分') + '</th><th class="uoj-ob-num">' + (icpc() ? '罚时' : '用时') + '</th>';
		if (icpc()) {
			html += '<th class="uoj-ob-num" title="已通过的题目里，未通过的提交占全部提交的比例">Dirt</th>';
		}
		return html + '</tr>';
	};
	var rowHtml = function(row) {
		// the medals go to the best three of the contestants: who sat the contest virtually takes none
		var html = '<td class="uoj-ob-rank"><span' + (row.medal ? ' class="uoj-ob-medal uoj-ob-medal-' + row.medal + '"' : '') + '>' + row.rank + '</span></td>';
		var name = '<span class="uoj-ob-nick">' + esc(row.nickname !== '' ? row.nickname : row.username) + '</span>'
			+ (row.nickname !== '' ? '<small>' + esc(row.username) + '</small>' : '');
		if (row.virtual) {
			// who sat it virtually is under a star, and their name shows the board as they see it
			name = '<a class="uoj-ob-vp" href="' + esc(viewOf(row.username)) + '" title="看 ' + esc(row.username) + ' 虚拟参赛时看到的榜单">'
				+ '<span class="uoj-ob-star">*</span>' + name + '</a>';
		}
		html += '<td class="uoj-ob-name">' + name + '</td>';
		$.each(row.cells, function(pos, cell) {
			var problem = state.problems[pos];
			var letter = problem ? esc(problem.letter) : '';
			if (cell[0] === 'none') {
				html += '<td class="uoj-ob-cell uoj-ob-none" data-problem="' + letter + '">·</td>';
				return;
			}
			html += '<td class="uoj-ob-cell uoj-ob-' + cell[0] + '" data-problem="' + letter + '"><b>' + esc(cell[1]) + '</b>'
				+ (cell[2] !== '' ? '<small>' + esc(cell[2]) + '</small>' : '') + '</td>';
		});
		html += '<td class="uoj-ob-num uoj-ob-total">' + row.solved + '</td>';
		html += '<td class="uoj-ob-num">' + (icpc() ? Math.floor(row.penalty / 60) : clock(row.penalty)) + '</td>';
		if (icpc()) {
			html += '<td class="uoj-ob-num uoj-ob-dirt">' + (row.dirt === null ? '–' : row.dirt + '%') + '</td>';
		}
		return html;
	};
	// Draws the table. With `moving`, a row that has another place than before glides there,
	// and a cell that says something else than before lights up.
	var draw = function(moving) {
		var table = $('#table-outer-board');
		var body = table.children('tbody');
		table.children('thead').html(head());
		var before = {};
		body.children('tr').each(function() {
			var tr = $(this);
			before[tr.attr('data-key')] = {el: tr, top: this.getBoundingClientRect().top, said: tr.data('said') || []};
		});
		var rows = [];
		var kept = {};
		$.each(state.rows, function(index, row) {
			var was = before[row.key];
			var tr = was ? was.el : $('<tr />');
			var said = $.map(row.cells, function(cell) {
				return cell.join('|');
			});
			tr.attr({'data-key': row.key, 'data-username': row.username, 'data-rank': row.rank, 'data-solved': row.solved, 'data-penalty': row.penalty, 'data-virtual': row.virtual ? 1 : 0})
				.attr('class', 'uoj-ob-band-' + row.band + (whose() !== '' && row.key === whose() ? ' uoj-ob-me' + (state.viewing ? ' uoj-ob-viewed' : '') : ''))
				.html(rowHtml(row)).data('said', said);
			if (moving && was) {
				tr.children('td.uoj-ob-cell').each(function(pos) {
					if (was.said[pos] !== undefined && was.said[pos] !== said[pos]) {
						$(this).addClass('uoj-ob-flash');
					}
				});
			} else if (moving) {
				tr.addClass('uoj-ob-new');
			}
			kept[row.key] = true;
			rows.push(tr[0]);
		});
		$.each(before, function(key, was) {
			if (!kept[key]) {
				was.el.remove();
			}
		});
		// putting a row that is in the table at its end moves it: the rows end up in their order
		body.append(rows);
		if (moving) {
			$.each(rows, function(index, el) {
				var was = before[el.getAttribute('data-key')];
				var by = was ? was.top - el.getBoundingClientRect().top : 0;
				if (Math.abs(by) < 1) {
					return;
				}
				// from where it was, to where it is
				el.style.transition = 'none';
				el.style.transform = 'translateY(' + by + 'px)';
				el.getBoundingClientRect();
				el.style.transition = 'transform 0.9s cubic-bezier(0.2, 0.8, 0.2, 1)';
				el.style.transform = '';
				if (by > 0) {
					$(el).addClass('uoj-ob-up');
				}
			});
			setTimeout(function() {
				body.find('.uoj-ob-flash, .uoj-ob-up, .uoj-ob-new').removeClass('uoj-ob-flash uoj-ob-up uoj-ob-new');
			}, 2600);
		}
		var nobody = state.phase !== 'before' && state.rows.length === 0;
		$('#outer-board-empty').toggle(state.phase === 'before' || nobody)
			.text(state.phase === 'before' ? '比赛开始之后，榜单会出现在这里。' : '还没有选手上榜：选手打开题目之后就会出现。');
		table.toggle(state.phase !== 'before');
		$('#outer-board-legend').toggle(state.phase !== 'before');
		root.attr({'data-phase': state.phase, 'data-frozen': state.frozen ? 1 : 0, 'data-hash': state.hash, 'data-rule': state.rule});
	};

	// ---- what stands over the table
	var words = function() {
		var phase = {
			before: '未开始', running: state.frozen ? '已封榜' : '进行中', waiting: state.frozen ? '已结束 · 封榜中' : '已结束 · 等待公布成绩',
			final: '最终榜单', replay: !state.ticking ? 'VP 视角 · 已结束' : (state.frozen ? '回放 · 已封榜' : '回放')
		}[state.phase];
		$('#outer-board-phase').text(phase).attr('class', 'uoj-ob-pill uoj-ob-pill-' + state.phase + (state.frozen ? ' uoj-ob-pill-frozen' : ''));
		var note = '';
		var viewing = state.viewing;
		if (viewing && viewing.mine) {
			note = '你正在虚拟参赛：这是原比赛进行到同一时刻的榜单，带 * 的是虚拟参赛的选手，也按各自的同一时刻计入。';
		} else if (viewing && viewing.running) {
			note = '这是 ' + viewing.username + ' 此刻看到的榜单：原比赛进行到 TA 虚拟参赛的同一时刻的样子，带 * 的是虚拟参赛的选手，也按各自的同一时刻计入。';
		} else if (viewing) {
			note = viewing.username + ' 的虚拟参赛已经结束：这是比赛的最终榜单，带 * 的是虚拟参赛的选手，TA 的那一行标了出来。';
		}
		if (state.frozen) {
			note += '榜单已封榜，这是比赛进行到 ' + clock(state.freeze) + ' 时的样子；此后的提交只显示次数' + (state.phase === 'replay' ? '。' : '，公布成绩后揭晓。');
		} else if (state.freeze !== null && (state.phase === 'running' || (state.phase === 'replay' && state.ticking) || state.phase === 'before')) {
			note += '榜单将在 ' + clock(state.freeze) + ' 之后封榜。';
		} else if (state.phase === 'waiting') {
			note += '比赛已结束，公布成绩后这里是最终榜单。';
		} else if (state.phase === 'final') {
			note += '比赛已结束，这是最终榜单。' + (state.virtual ? '带 * 的是赛后虚拟参赛的选手，和正式选手一起排名，不是正式成绩。' : '');
		}
		$('#outer-board-note').text(note);
		// Codeforces heads the board of a participant with whose it is: so does this one
		var line = $('#outer-board-viewing');
		if (viewing) {
			line.html((viewing.mine ? '你的虚拟参赛' : '<strong>' + esc(viewing.nickname !== '' ? viewing.nickname + '（' + viewing.username + '）' : viewing.username) + '</strong> 的虚拟参赛')
				+ ' · 开始于 ' + esc(viewing.start_time) + ' · ' + (viewing.running ? '进行中' : '已结束')
				+ (viewing.mine ? '' : ' · <a href="' + esc(here) + '">看正式榜单</a>')).show();
		} else {
			line.hide();
		}
		// whose view to see, of the people who sat it virtually
		var select = $('#outer-board-vp');
		if (state.virtuals && state.virtuals.length > 0) {
			var options = '<option value="">' + (viewing ? '回到正式榜单' : 'VP 视角…') + '</option>';
			$.each(state.virtuals, function(index, person) {
				var label = '* ' + (person.nickname !== '' ? person.nickname + '（' + person.username + '）' : person.username) + (person.running ? ' · 进行中' : '');
				options += '<option value="' + esc(person.username) + '"' + (viewing && viewing.username === person.username ? ' selected="selected"' : '') + '>' + esc(label) + '</option>';
			});
			select.html(options).show();
		} else {
			select.hide();
		}
		// the board of a contest that is over with or without the people who sat it virtually
		var shown = state.phase === 'final' && state.virtuals && state.virtuals.length > 0;
		$('#outer-board-virtual').toggle(shown).text(state.virtual ? '隐藏虚拟参赛' : '显示虚拟参赛')
			.toggleClass('uoj-ob-button-on', state.virtual);
		$('#outer-board-legend-virtual').toggle(state.virtual);
		var frozen = $('#outer-board-progress-frozen');
		if (state.freeze !== null && state.duration > 0) {
			frozen.css({left: (100 * state.freeze / state.duration) + '%', width: (100 - 100 * state.freeze / state.duration) + '%'}).show();
		} else {
			frozen.hide();
		}
	};
	var tick = function() {
		var passed = state.ticking || state.phase === 'before' ? (Date.now() - told) / 1000 : 0;
		if (state.phase === 'before') {
			$('#outer-board-clock-label').text('距离开始');
			$('#outer-board-clock').text(clock((state.starts_in || 0) - passed));
			$('#outer-board-clock-of').text('');
			$('#outer-board-progress-done').css('width', '0%');
			return;
		}
		var elapsed = Math.min(state.duration, state.elapsed + passed);
		$('#outer-board-clock-label').text(state.phase === 'replay' && state.ticking ? '回放时间' : (state.ticking ? '当前时间' : '比赛时间'));
		$('#outer-board-clock').text(clock(elapsed));
		$('#outer-board-clock-of').text('/ ' + clock(state.duration));
		$('#outer-board-progress-done').css('width', (state.duration > 0 ? 100 * elapsed / state.duration : 100) + '%');
	};
	var live = function(ok) {
		var now = new Date();
		$('#outer-board-live').toggleClass('uoj-ob-live-off', !ok).children('span')
			.text(ok ? '实时 · ' + two(now.getHours()) + ':' + two(now.getMinutes()) + ':' + two(now.getSeconds()) : '连接中断，重试中');
	};

	// ---- keeping up to date
	var every = function() {
		if (failures > 0) {
			return Math.min(30000, 3000 * Math.pow(2, failures));
		}
		if (state.phase === 'running' || (state.phase === 'replay' && state.ticking)) {
			return 3000;
		}
		// somebody sits the contest virtually: their row on the board moves
		if (state.virtual && $.grep(state.virtuals || [], function(person) {
			return person.running;
		}).length > 0) {
			return 5000;
		}
		if (state.phase === 'before') {
			return state.starts_in !== null && state.starts_in < 300 ? 3000 : 15000;
		}
		return state.phase === 'waiting' ? 10000 : 60000;
	};
	var later = function() {
		clearTimeout(timer);
		timer = setTimeout(ask, every());
	};
	var ask = function() {
		// nobody looks at a page that is behind another: it asks again when it is looked at
		if (document.hidden) {
			later();
			return;
		}
		$.ajax({url: url, data: {have: state.hash}, dataType: 'json', cache: false, timeout: 10000}).done(function(next) {
			failures = 0;
			told = Date.now();
			if (next.same) {
				state.elapsed = next.elapsed;
				state.ticking = next.ticking;
				state.starts_in = next.starts_in;
			} else {
				state = next;
				draw(true);
				words();
			}
			tick();
			live(true);
		}).fail(function() {
			failures++;
			live(false);
		}).always(later);
	};
	$(document).on('visibilitychange', function() {
		if (!document.hidden) {
			clearTimeout(timer);
			ask();
		}
	});

	// ---- how it is looked at: dark for a wall, the whole screen, scrolling by itself
	$('#outer-board-vp').on('change', function() {
		window.location.href = viewOf($(this).val());
	});
	$('#outer-board-virtual').on('click', function() {
		window.location.href = here + (state.virtual ? '?virtual=0' : '');
	});
	var dark = function(on) {
		$('body').toggleClass('uoj-ob-dark-page', on);
		$('#outer-board-theme').text(on ? '浅色' : '深色');
	};
	dark(keep('theme') === 'dark');
	$('#outer-board-theme').on('click', function() {
		var on = !$('body').hasClass('uoj-ob-dark-page');
		dark(on);
		keep('theme', on ? 'dark' : 'light');
	});
	var whole = document.documentElement;
	if (!whole.requestFullscreen) {
		$('#outer-board-full').hide();
	}
	$('#outer-board-full').on('click', function() {
		if (document.fullscreenElement) {
			document.exitFullscreen();
		} else {
			whole.requestFullscreen();
		}
	});
	$(document).on('fullscreenchange', function() {
		$('#outer-board-full').text(document.fullscreenElement ? '退出全屏' : '全屏');
	});
	// A board that is longer than the wall goes down by itself, rests at its end, and begins
	// again from the top.
	var scrolling = null;
	var resting = 0;
	var scroll = function(on) {
		clearInterval(scrolling);
		scrolling = null;
		$('#outer-board-scroll').toggleClass('uoj-ob-button-on', on);
		if (!on) {
			return;
		}
		scrolling = setInterval(function() {
			if (resting > 0) {
				resting--;
				return;
			}
			var end = document.documentElement.scrollHeight - window.innerHeight;
			if (end <= 0) {
				return;
			}
			if (window.pageYOffset >= end - 1) {
				window.scrollTo(0, 0);
				resting = 100;
			} else {
				window.scrollBy(0, 1);
				if (window.pageYOffset >= end - 1) {
					resting = 100;
				}
			}
		}, 40);
	};
	scroll(keep('scroll') === 'on');
	$('#outer-board-scroll').on('click', function() {
		var on = scrolling === null;
		scroll(on);
		keep('scroll', on ? 'on' : 'off');
	});

	draw(false);
	words();
	tick();
	live(true);
	// the bar stands where the contest is, and moves on from there
	setTimeout(function() {
		$('#outer-board-progress').addClass('uoj-ob-progress-going');
	}, 50);
	setInterval(tick, 1000);
	later();
})(jQuery);
