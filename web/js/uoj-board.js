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
	// hours and minutes, as a cell says when
	var minutes = function(seconds) {
		return Math.floor(seconds / 3600) + ':' + two(Math.floor(seconds / 60) % 60);
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
	// Black or white, whichever reads better on a colour '#rrggbb' (the colours of balloons).
	var inkOn = function(color) {
		var m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(color);
		if (!m) {
			return '#ffffff';
		}
		var lum = function(hex) {
			var c = parseInt(hex, 16) / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
		};
		var l = 0.2126 * lum(m[1]) + 0.7152 * lum(m[2]) + 0.0722 * lum(m[3]);
		return l > 0.36 ? '#111111' : '#ffffff';
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

	// ---- who is shown: a part of a name looked for, and the people followed (kept in the browser,
	// for every board alike)
	var follows = {};
	try {
		$.each(JSON.parse(keep('follow') || '[]'), function(index, username) {
			follows[String(username)] = true;
		});
	} catch (e) {
		follows = {};
	}
	var followed = function(username) {
		return follows[username] === true;
	};
	var follow = function(username, on) {
		if (on) {
			follows[username] = true;
		} else {
			delete follows[username];
		}
		keep('follow', JSON.stringify($.map(follows, function(yes, name) {
			return name;
		})));
	};
	var onlyFollowed = keep('only-followed') === 'on';
	var looked = '';
	// whether somebody of the board is shown: who sat the contest virtually is never followed
	var matchesName = function(username, nickname) {
		if (onlyFollowed && !followed(username)) {
			return false;
		}
		return looked === '' || username.toLowerCase().indexOf(looked) >= 0 || String(nickname || '').toLowerCase().indexOf(looked) >= 0;
	};
	var shown = function(row) {
		return matchesName(row.username, row.nickname);
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
	// Under the table, as XCPCIO has it: of every problem how many submissions there were, how
	// many tried it and how many solved it, how much of what was judged failed, and when it was
	// first and last solved. Percentages are cut to whole ones.
	var foot = function() {
		if (!state.stats || state.stats.length === 0 || state.rows.length === 0) {
			return '';
		}
		var pct = function(part, whole) {
			return whole > 0 ? '<small>' + Math.floor(100 * part / whole) + '%</small>' : '';
		};
		var at = function(seconds) {
			return seconds === null || seconds === undefined ? '–' : minutes(seconds);
		};
		var lines = icpc() ? [
			['submitted', '提交', function(problem) {
				return String(problem.tries);
			}],
			['attempted', '尝试', function(problem, stat) {
				return stat.people + pct(stat.people, state.rows.length);
			}],
			['accepted', '通过', function(problem, stat) {
				return problem.solved + pct(problem.solved, stat.people);
			}],
			['dirt', 'Dirt', function(problem, stat) {
				return stat.rejected + pct(stat.rejected, problem.tries - stat.pending);
			}],
			['first', '最早通过', function(problem, stat) {
				return at(stat.first);
			}],
			['last', '最晚通过', function(problem, stat) {
				return at(stat.last);
			}]
		] : [
			['attempted', '尝试', function(problem, stat) {
				return stat.people + pct(stat.people, state.rows.length);
			}],
			['accepted', '满分', function(problem, stat) {
				return problem.solved + pct(problem.solved, stat.people);
			}],
			['first', '最早满分', function(problem, stat) {
				return at(stat.first);
			}],
			['last', '最晚满分', function(problem, stat) {
				return at(stat.last);
			}]
		];
		var html = '';
		$.each(lines, function(index, line) {
			html += '<tr class="uoj-ob-stat" data-stat="' + line[0] + '"><th class="uoj-ob-stat-label" colspan="2">' + esc(line[1]) + '</th>';
			$.each(state.problems, function(pos, problem) {
				html += '<td class="uoj-ob-stat-cell" data-problem="' + esc(problem.letter) + '">' + line[2](problem, state.stats[pos]) + '</td>';
			});
			html += '<td class="uoj-ob-stat-rest" colspan="' + (icpc() ? 3 : 2) + '"></td></tr>';
		});
		return html;
	};
	var rowHtml = function(row) {
		// the medals of the contest, in their colours: who sat the contest virtually takes none
		var medal = row.medal ? state.medals[row.medal - 1] : null;
		var html = '<td class="uoj-ob-rank"><span' + (medal ? ' class="uoj-ob-medal uoj-ob-medal-' + row.medal + '" title="' + esc(medal.name) + '"'
			+ ' style="background-color: ' + esc(medal.color) + '; color: ' + inkOn(medal.color) + '"' : '') + '>' + row.rank + '</span></td>';
		var name = '<span class="uoj-ob-nick">' + esc(row.nickname !== '' ? row.nickname : row.username) + '</span>'
			+ (row.nickname !== '' ? '<small>' + esc(row.username) + '</small>' : '');
		if (row.virtual) {
			// who sat it virtually is under a star, and their name shows the board as they see it
			name = '<a class="uoj-ob-vp" href="' + esc(viewOf(row.username)) + '" title="看 ' + esc(row.username) + ' 虚拟参赛时看到的榜单">'
				+ '<span class="uoj-ob-star">*</span>' + name + '</a>';
		} else {
			// a contestant may be followed, and their name tells what they submitted when
			var on = followed(row.username);
			name = '<button type="button" class="uoj-ob-follow' + (on ? ' uoj-ob-follow-on' : '') + '" data-username="' + esc(row.username) + '"'
				+ ' aria-pressed="' + (on ? 'true' : 'false') + '" title="' + (on ? '取消关注' : '关注') + '">' + (on ? '★' : '☆') + '</button>'
				+ '<a href="#" class="uoj-ob-person" data-username="' + esc(row.username) + '" title="看 TA 的提交">' + name + '</a>';
		}
		html += '<td class="uoj-ob-name">' + name + '</td>';
		$.each(row.cells, function(pos, cell) {
			var problem = state.problems[pos];
			var letter = problem ? esc(problem.letter) : '';
			if (cell[0] === 'none') {
				html += '<td class="uoj-ob-cell uoj-ob-none" data-problem="' + letter + '">·</td>';
				return;
			}
			// As DOMjudge has it: who solved a problem first is in the colour of its balloon,
			// where the contest gives balloons; else in the green of the first.
			var paint = cell[0] === 'first' && problem && problem.color
				? ' style="background-color: ' + esc(problem.color) + '; color: ' + inkOn(problem.color) + '" data-balloon="' + esc(problem.color) + '"' : '';
			html += '<td class="uoj-ob-cell uoj-ob-' + cell[0] + '" data-problem="' + letter + '"' + paint + '><b>' + esc(cell[1]) + '</b>'
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
		table.children('tfoot').html(foot());
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
				.attr('class', 'uoj-ob-band-' + row.band + (whose() !== '' && row.key === whose() ? ' uoj-ob-me' + (state.viewing ? ' uoj-ob-viewed' : '') : '')
					+ (!row.virtual && followed(row.username) ? ' uoj-ob-followed' : '') + (shown(row) ? '' : ' uoj-ob-hidden'))
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
		var visible = $.grep(state.rows, shown).length;
		$('#outer-board-shown').text(visible < state.rows.length ? '显示 ' + visible + ' / ' + state.rows.length + ' 人' : '');
		var nobody = state.phase !== 'before' && state.rows.length === 0;
		$('#outer-board-empty').toggle(state.phase === 'before' || nobody)
			.text(state.phase === 'before' ? '比赛尚未开始' : '还没有选手');
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
		var viewing = state.viewing;
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
		medalLegend();
		var frozen = $('#outer-board-progress-frozen');
		if (state.freeze !== null && state.duration > 0) {
			frozen.css({left: (100 * state.freeze / state.duration) + '%', width: (100 - 100 * state.freeze / state.duration) + '%'}).show();
		} else {
			frozen.hide();
		}
	};
	// what the medals are, and how many each went to
	var medalLegend = function() {
		var kinds = state.medals || [];
		var html = '';
		$.each(kinds, function(index, kind) {
			// its name, and how many got it
			html += '<span class="uoj-ob-medal-kind" data-medal="' + (index + 1) + '"><i style="background-color: ' + esc(kind.color) + '"></i>'
				+ esc(kind.name) + '<small>' + kind.given + ' 人</small></span>';
		});
		$('#outer-board-medals').html(html).toggle(kinds.length > 0 && state.phase !== 'before');
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

	// ---- the tabs: the board, what was submitted to the contest, its statistics
	var tab = 'board';
	var shownRuns = 100;
	// The submissions are asked for only while somebody looks at them: the board is asked for
	// every few seconds by everybody who watches it.
	var wantsRuns = function() {
		return (tab !== 'board' || person !== null) && icpc();
	};
	// what somebody is called on the board
	var nameOf = function(username) {
		var named = username;
		$.each(state.rows, function(index, row) {
			if (row.username === username && !row.virtual) {
				named = row.nickname !== '' ? row.nickname + '（' + row.username + '）' : row.username;
				return false;
			}
		});
		return named;
	};
	// the choice of a problem, keeping what was chosen
	var problemChoice = function(select) {
		var chosen = select.val() || '';
		var options = '<option value="">全部</option>';
		$.each(state.problems, function(pos, problem) {
			options += '<option value="' + pos + '">' + esc(problem.letter) + '</option>';
		});
		select.html(options).val(chosen);
		if (select.val() === null) {
			select.val('');
		}
		return select.val() === '' ? null : parseInt(select.val(), 10);
	};
	var said = {yes: '✓ 通过', no: '✗ 未通过', wait: '? 待定'};
	var drawRuns = function() {
		var body = $('#table-outer-board-runs tbody');
		var problem = problemChoice($('#outer-board-runs-problem'));
		var outcome = $('#outer-board-runs-outcome').val();
		if (!state.runs) {
			body.html('<tr><td colspan="4" class="uoj-ob-runs-empty">正在载入……</td></tr>');
			$('#outer-board-runs-more').hide();
			return;
		}
		var nicknames = {};
		$.each(state.rows, function(index, row) {
			if (!row.virtual) {
				nicknames[row.username] = row.nickname;
			}
		});
		var runs = $.grep(state.runs, function(run) {
			return (problem === null || run[2] === problem) && (!outcome || run[3] === outcome) && matchesName(run[1], nicknames[run[1]]);
		}).reverse();
		$('#outer-board-runs-count').text('共 ' + runs.length + ' 次提交');
		var html = '';
		$.each(runs.slice(0, shownRuns), function(index, run) {
			var letter = state.problems[run[2]] ? state.problems[run[2]].letter : '?';
			var color = state.problems[run[2]] ? state.problems[run[2]].color : null;
			html += '<tr data-username="' + esc(run[1]) + '"' + (followed(run[1]) ? ' class="uoj-ob-followed"' : '') + '><td class="uoj-ob-runs-time">' + clock(run[0]) + '</td>'
				+ '<td class="uoj-ob-runs-name"><a href="#" class="uoj-ob-person" data-username="' + esc(run[1]) + '">' + esc(nameOf(run[1])) + '</a></td>'
				+ '<td class="uoj-ob-runs-problem">' + (color ? '<i style="background-color: ' + esc(color) + '"></i>' : '') + esc(letter) + '</td>'
				+ '<td><span class="uoj-ob-verdict uoj-ob-verdict-' + run[3] + '">' + said[run[3]] + '</span></td></tr>';
		});
		body.html(html || '<tr><td colspan="4" class="uoj-ob-runs-empty">没有提交</td></tr>');
		$('#outer-board-runs-more').toggle(runs.length > shownRuns);
	};

	// ---- the charts of the statistics: SVG, in the colours of the board
	var svg = function(width, height, inner, label) {
		return '<svg class="uoj-ob-svg" viewBox="0 0 ' + width + ' ' + height + '" width="' + width + '" height="' + height + '" role="img" aria-label="' + esc(label) + '">' + inner + '</svg>';
	};
	// a bar or a column with its end rounded and the end at its baseline square
	var bar = function(x, y, w, h, cls, end) {
		if (w <= 0 || h <= 0) {
			return '';
		}
		var r = Math.min(4, end === 'right' ? w : h, end === 'right' ? h / 2 : w / 2);
		var d = end === 'right'
			? 'M' + x + ',' + y + 'h' + (w - r) + 'a' + r + ',' + r + ' 0 0 1 ' + r + ',' + r + 'v' + (h - 2 * r) + 'a' + r + ',' + r + ' 0 0 1 ' + (-r) + ',' + r + 'h' + (r - w) + 'z'
			: 'M' + x + ',' + (y + h) + 'v' + (r - h) + 'a' + r + ',' + r + ' 0 0 1 ' + r + ',' + (-r) + 'h' + (w - 2 * r) + 'a' + r + ',' + r + ' 0 0 1 ' + r + ',' + r + 'v' + (h - r) + 'z';
		return '<path class="' + cls + '" d="' + d + '"></path>';
	};
	var square = function(x, y, w, h, cls) {
		return w > 0 && h > 0 ? '<rect class="' + cls + '" x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '"></rect>' : '';
	};
	// what a mark says when it is pointed at or reached by the keyboard
	var hit = function(x, y, w, h, tip) {
		return '<rect class="uoj-ob-hit" x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '" tabindex="0" data-tip="' + esc(tip) + '"></rect>';
	};
	// clean steps for an axis of counts: 1, 2, 5, 10, 20, 50, ...
	var ticks = function(max) {
		var step = 1;
		for (var k = 0; max / step > 5; k++) {
			step = [1, 2, 5][(k + 1) % 3] * Math.pow(10, Math.floor((k + 1) / 3));
		}
		var top = Math.max(step, Math.ceil(max / step) * step);
		var list = [];
		for (var v = 0; v <= top; v += step) {
			list.push(v);
		}
		return list;
	};
	var table = function(heads, rows) {
		var html = '<details class="uoj-ob-chart-table"><summary>看表格</summary><table><thead><tr>';
		$.each(heads, function(index, head) {
			html += '<th>' + esc(head) + '</th>';
		});
		html += '</tr></thead><tbody>';
		$.each(rows, function(index, row) {
			html += '<tr>' + $.map(row, function(cell) {
				return '<td>' + esc(cell) + '</td>';
			}).join('') + '</tr>';
		});
		return html + '</tbody></table></details>';
	};
	var chartWidth = function(id) {
		return Math.max(320, Math.min(1100, Math.floor($('#' + id).width() || 800)));
	};
	// of every problem how many tried it, and how many of them solved it
	var problemsChart = function() {
		var width = chartWidth('outer-board-chart-problems');
		var left = 40, right = 90, row = 30, thick = 16;
		var max = 1;
		$.each(state.problems, function(pos) {
			max = Math.max(max, state.stats[pos] ? state.stats[pos].people : 0);
		});
		var span = width - left - right;
		var inner = '';
		var rows = [];
		$.each(state.problems, function(pos, problem) {
			var stat = state.stats[pos] || {people: 0};
			var y = pos * row + (row - thick) / 2;
			var tried = span * stat.people / max;
			var solved = span * problem.solved / max;
			var share = stat.people > 0 ? Math.floor(100 * problem.solved / stat.people) + '%' : '–';
			inner += '<text class="uoj-ob-axis-label" x="' + (left - 10) + '" y="' + (y + thick / 2) + '" text-anchor="end" dominant-baseline="central">' + esc(problem.letter) + '</text>';
			inner += bar(left, y, tried, thick, 'uoj-ob-mark-track', 'right') + bar(left, y, solved, thick, 'uoj-ob-mark-yes', 'right');
			inner += '<text class="uoj-ob-value" x="' + (left + tried + 8) + '" y="' + (y + thick / 2) + '" dominant-baseline="central">' + problem.solved + ' / ' + stat.people + '</text>';
			inner += hit(0, pos * row, width, row, problem.letter + ' 题：交过 ' + stat.people + ' 人，通过 ' + problem.solved + ' 人（' + share + '）');
			rows.push([problem.letter, String(stat.people), String(problem.solved), share]);
		});
		var height = Math.max(1, state.problems.length) * row + 4;
		$('#outer-board-chart-problems .uoj-ob-chart-body').html(svg(width, height, inner, '每题通过人数') + table(['题目', '交过的人数', '通过的人数', '通过的比例'], rows));
	};
	// what was submitted, in slices of the contest, by how it went
	var timeChart = function() {
		var section = $('#outer-board-chart-time');
		section.add('#outer-board-stats-filters').toggle(icpc());
		if (!icpc()) {
			return;
		}
		var body = section.find('.uoj-ob-chart-body');
		if (!state.runs) {
			body.html('<p class="uoj-ob-runs-empty">正在载入……</p>');
			return;
		}
		var problem = problemChoice($('#outer-board-stats-problem'));
		var minutesOf = 5;
		$.each([5, 10, 15, 20, 30, 60, 120, 240, 480, 1440], function(index, size) {
			minutesOf = size;
			return state.duration / (size * 60) > 36;
		});
		var size = minutesOf * 60;
		var n = Math.max(1, Math.ceil(state.duration / size));
		var counts = [];
		for (var i = 0; i < n; i++) {
			counts.push({yes: 0, wait: 0, no: 0});
		}
		$.each(state.runs, function(index, run) {
			if (problem === null || run[2] === problem) {
				counts[Math.min(n - 1, Math.floor(run[0] / size))][run[3]]++;
			}
		});
		var max = 1;
		$.each(counts, function(index, c) {
			max = Math.max(max, c.yes + c.wait + c.no);
		});
		var axis = ticks(max);
		var top = axis[axis.length - 1];
		var width = chartWidth('outer-board-chart-time');
		var left = 40, right = 12, upper = 20, plot = 200, lower = 26;
		var slot = (width - left - right) / n;
		var thick = Math.max(2, Math.min(24, slot - 4));
		var y = function(v) {
			return upper + plot - plot * v / top;
		};
		var inner = '';
		$.each(axis, function(index, v) {
			inner += '<line class="uoj-ob-grid" x1="' + left + '" x2="' + (width - right) + '" y1="' + y(v) + '" y2="' + y(v) + '"></line>'
				+ '<text class="uoj-ob-axis-label" x="' + (left - 8) + '" y="' + y(v) + '" text-anchor="end" dominant-baseline="central">' + v + '</text>';
		});
		var labelled = Math.ceil(n / Math.max(1, Math.floor((width - left - right) / 64)));
		var rows = [];
		$.each(counts, function(index, c) {
			var x = left + index * slot + (slot - thick) / 2;
			var base = upper + plot;
			// passed at the bottom, then what is not told, then what failed: a gap of the surface between
			$.each([['yes', 'uoj-ob-mark-yes'], ['wait', 'uoj-ob-mark-wait'], ['no', 'uoj-ob-mark-no']], function(k, part) {
				var h = plot * c[part[0]] / top;
				if (h <= 0) {
					return;
				}
				var last = (part[0] === 'no') || (part[0] === 'wait' && c.no === 0) || (part[0] === 'yes' && c.wait === 0 && c.no === 0);
				var drawn = Math.max(1, h - (last ? 0 : 2));
				inner += last ? bar(x, base - drawn, thick, drawn, part[1], 'top') : square(x, base - drawn, thick, drawn, part[1]);
				base -= h;
			});
			var from = minutes(index * size), to = minutes(Math.min(state.duration, (index + 1) * size));
			if (index % labelled === 0) {
				inner += '<text class="uoj-ob-axis-label" x="' + (left + index * slot) + '" y="' + (upper + plot + 16) + '" text-anchor="start">' + from + '</text>';
			}
			inner += hit(left + index * slot, upper, slot, plot, from + '–' + to + '：通过 ' + c.yes + '，待定 ' + c.wait + '，未通过 ' + c.no);
			rows.push([from + '–' + to, String(c.yes), String(c.wait), String(c.no)]);
		});
		inner += '<line class="uoj-ob-axis" x1="' + left + '" x2="' + (width - right) + '" y1="' + (upper + plot) + '" y2="' + (upper + plot) + '"></line>';
		if (state.freeze !== null && state.freeze !== undefined) {
			var fx = left + (width - left - right) * state.freeze / state.duration;
			inner += '<line class="uoj-ob-freeze" x1="' + fx + '" x2="' + fx + '" y1="' + upper + '" y2="' + (upper + plot) + '"></line>'
				// over the plot, where no column reaches
				+ '<text class="uoj-ob-axis-label" x="' + fx + '" y="' + (upper - 4) + '" text-anchor="middle">封榜</text>';
		}
		body.html(svg(width, upper + plot + lower, inner, '提交随时间') + table(['比赛时间', '通过', '待定', '未通过'], rows));
	};
	// how many of the contestants solved so many problems
	var solvedChart = function() {
		var n = state.problems.length;
		var counts = [];
		for (var k = 0; k <= n; k++) {
			counts.push(0);
		}
		$.each(state.rows, function(index, row) {
			if (!row.virtual) {
				counts[$.grep(row.cells, function(cell) {
					return cell[0] === 'solved' || cell[0] === 'first';
				}).length]++;
			}
		});
		var max = 1;
		$.each(counts, function(index, c) {
			max = Math.max(max, c);
		});
		var axis = ticks(max);
		var top = axis[axis.length - 1];
		var width = Math.min(chartWidth('outer-board-chart-solved'), 120 + 56 * (n + 1));
		var left = 40, right = 12, upper = 18, plot = 160, lower = 26;
		var slot = (width - left - right) / (n + 1);
		var thick = Math.min(24, slot - 6);
		var y = function(v) {
			return upper + plot - plot * v / top;
		};
		var inner = '';
		$.each(axis, function(index, v) {
			inner += '<line class="uoj-ob-grid" x1="' + left + '" x2="' + (width - right) + '" y1="' + y(v) + '" y2="' + y(v) + '"></line>'
				+ '<text class="uoj-ob-axis-label" x="' + (left - 8) + '" y="' + y(v) + '" text-anchor="end" dominant-baseline="central">' + v + '</text>';
		});
		var rows = [];
		$.each(counts, function(k, c) {
			var x = left + k * slot + (slot - thick) / 2;
			inner += bar(x, y(c), thick, upper + plot - y(c), 'uoj-ob-mark-accent', 'top');
			if (c > 0) {
				inner += '<text class="uoj-ob-value" x="' + (x + thick / 2) + '" y="' + (y(c) - 5) + '" text-anchor="middle">' + c + '</text>';
			}
			inner += '<text class="uoj-ob-axis-label" x="' + (x + thick / 2) + '" y="' + (upper + plot + 16) + '" text-anchor="middle">' + k + '</text>';
			inner += hit(left + k * slot, upper, slot, plot, '通过 ' + k + ' 道：' + c + ' 人');
			rows.push([String(k), String(c)]);
		});
		inner += '<line class="uoj-ob-axis" x1="' + left + '" x2="' + (width - right) + '" y1="' + (upper + plot) + '" y2="' + (upper + plot) + '"></line>';
		$('#outer-board-chart-solved .uoj-ob-chart-body').html(svg(width, upper + plot + lower, inner, '解题数分布') + table(['通过的题数', '人数'], rows));
	};
	var drawStats = function() {
		if (state.phase === 'before') {
			return;
		}
		problemsChart();
		timeChart();
		solvedChart();
	};
	var panels = function() {
		$('#outer-board-tab-runs').toggle(icpc());
		$('#outer-board-people-filters').toggle(tab !== 'stats' && state.phase !== 'before');
		if (person !== null) {
			drawPerson();
		}
		if (tab === 'runs') {
			drawRuns();
		} else if (tab === 'stats') {
			drawStats();
		}
	};
	var showTab = function(next) {
		if (next !== 'runs' && next !== 'stats' || (next === 'runs' && !icpc())) {
			next = 'board';
		}
		var before = tab;
		tab = next;
		$('#outer-board-tabs .uoj-ob-tab').each(function() {
			var on = $(this).data('tab') === tab;
			$(this).toggleClass('uoj-ob-tab-on', on).attr('aria-selected', on ? 'true' : 'false');
		});
		$('#outer-board-panel-board').toggle(tab === 'board');
		$('#outer-board-panel-runs').toggle(tab === 'runs');
		$('#outer-board-panel-stats').toggle(tab === 'stats');
		root.attr('data-tab', tab);
		try {
			window.history.replaceState(null, '', window.location.pathname + window.location.search + (tab === 'board' ? '' : '#' + tab));
		} catch (e) {
			// the address keeps what it had
		}
		panels();
		// what was submitted is asked for as soon as somebody wants to see it
		if (wantsRuns() && !state.runs && before !== tab && timer !== null) {
			clearTimeout(timer);
			ask();
		}
	};
	$('#outer-board-tabs').on('click', '.uoj-ob-tab', function() {
		showTab($(this).data('tab'));
	});
	$('#outer-board-runs-problem, #outer-board-runs-outcome').on('change', function() {
		shownRuns = 100;
		drawRuns();
	});
	$('#outer-board-runs-more').on('click', function() {
		shownRuns += 100;
		drawRuns();
	});
	$('#outer-board-stats-problem').on('change', timeChart);
	var resized = null;
	$(window).on('resize', function() {
		clearTimeout(resized);
		resized = setTimeout(panels, 200);
	});
	// one tip for every mark, where it is pointed at or reached by the keyboard
	var tip = $('#outer-board-tip');
	root.on('pointermove focusin', '.uoj-ob-hit', function(e) {
		var mark = this.getBoundingClientRect();
		tip.text($(this).attr('data-tip')).show();
		var x = e.type === 'focusin' ? mark.left + mark.width / 2 : e.clientX;
		var y = e.type === 'focusin' ? mark.top : e.clientY;
		tip.css({left: Math.min(window.innerWidth - tip.outerWidth() - 8, Math.max(8, x + 12)) + 'px', top: Math.max(8, y - tip.outerHeight() - 10) + 'px'});
		$(this).addClass('uoj-ob-hit-on');
	});
	root.on('pointerleave focusout', '.uoj-ob-hit', function() {
		tip.hide();
		$(this).removeClass('uoj-ob-hit-on');
	});

	// ---- one contestant: where they stand, and what they submitted when, problem by problem
	var person = null;
	var drawPerson = function() {
		var box = $('#outer-board-person');
		var row = null;
		$.each(state.rows, function(index, one) {
			if (one.username === person && !one.virtual) {
				row = one;
				return false;
			}
		});
		if (person === null || !row) {
			box.hide();
			person = null;
			return;
		}
		$('#outer-board-person-name').text(nameOf(person));
		var medal = row.medal ? state.medals[row.medal - 1] : null;
		$('#outer-board-person-sum').text('排名 ' + row.rank + ' · ' + (icpc() ? '通过 ' + row.solved + ' 题 · 罚时 ' + Math.floor(row.penalty / 60) + (row.dirt === null ? '' : ' · Dirt ' + row.dirt + '%')
			: '总分 ' + row.solved + ' · 用时 ' + clock(row.penalty)) + (medal ? ' · ' + medal.name : ''));
		$('#outer-board-person-key').toggle(icpc());
		var body = $('#outer-board-person-body');
		if (!icpc()) {
			// a board of scores tells the last submission to every problem, with its score
			var rows = [];
			$.each(row.cells, function(pos, cell) {
				if (cell[0] !== 'none') {
					rows.push('<tr><td>' + esc(state.problems[pos].letter) + '</td><td>' + esc(cell[1]) + '</td><td>' + esc(cell[2]) + '</td></tr>');
				}
			});
			body.html(rows.length ? '<table class="uoj-ob-runs"><thead><tr><th>题目</th><th>得分</th><th>时间</th></tr></thead><tbody>' + rows.join('') + '</tbody></table>'
				: '<p class="uoj-ob-runs-empty">还没有提交</p>');
			return;
		}
		if (!state.runs) {
			body.html('<p class="uoj-ob-runs-empty">正在载入……</p>');
			return;
		}
		var runs = $.grep(state.runs, function(run) {
			return run[1] === person;
		});
		// a line of every problem, with what was submitted to it along the contest
		var width = Math.max(320, Math.min(640, Math.floor(body.width() || 560)));
		var left = 34, right = 14, line = 26, upper = 6, lower = 22;
		var span = width - left - right;
		var xOf = function(seconds) {
			return left + span * Math.min(1, seconds / Math.max(1, state.duration));
		};
		var inner = '';
		for (var hour = 0; hour * 3600 <= state.duration; hour++) {
			inner += '<line class="uoj-ob-grid" x1="' + xOf(hour * 3600) + '" x2="' + xOf(hour * 3600) + '" y1="' + upper + '" y2="' + (upper + line * state.problems.length) + '"></line>'
				+ '<text class="uoj-ob-axis-label" x="' + xOf(hour * 3600) + '" y="' + (upper + line * state.problems.length + 15) + '" text-anchor="middle">' + hour + ':00</text>';
		}
		if (state.freeze !== null && state.freeze !== undefined) {
			inner += '<line class="uoj-ob-freeze" x1="' + xOf(state.freeze) + '" x2="' + xOf(state.freeze) + '" y1="' + upper + '" y2="' + (upper + line * state.problems.length) + '"></line>';
		}
		$.each(state.problems, function(pos, problem) {
			var y = upper + pos * line + line / 2;
			inner += '<text class="uoj-ob-axis-label" x="' + (left - 10) + '" y="' + y + '" text-anchor="end" dominant-baseline="central">' + esc(problem.letter) + '</text>'
				+ '<line class="uoj-ob-axis" x1="' + left + '" x2="' + (width - right) + '" y1="' + y + '" y2="' + y + '"></line>';
		});
		var list = [];
		$.each(runs, function(index, run) {
			var y = upper + run[2] * line + line / 2;
			var letter = state.problems[run[2]] ? state.problems[run[2]].letter : '?';
			inner += '<circle class="uoj-ob-dot uoj-ob-mark-' + run[3] + '" cx="' + xOf(run[0]) + '" cy="' + y + '" r="5"></circle>'
				+ '<circle class="uoj-ob-hit" cx="' + xOf(run[0]) + '" cy="' + y + '" r="12" tabindex="0" data-tip="' + esc(clock(run[0]) + ' · ' + letter + ' 题 · ' + said[run[3]]) + '"></circle>';
			list.push([clock(run[0]), letter, said[run[3]]]);
		});
		body.html(svg(width, upper + line * state.problems.length + lower, inner, nameOf(person) + ' 的提交')
			+ (runs.length ? table(['比赛时间', '题目', '结果'], list.reverse()) : '<p class="uoj-ob-runs-empty">还没有提交</p>'));
	};
	var openPerson = function(username) {
		person = username;
		$('#outer-board-person').show();
		drawPerson();
		$('#outer-board-person-close').focus();
		if (wantsRuns() && !state.runs) {
			clearTimeout(timer);
			ask();
		}
	};
	var closePerson = function() {
		person = null;
		$('#outer-board-person').hide();
	};
	root.on('click', '.uoj-ob-person', function(e) {
		e.preventDefault();
		openPerson(String($(this).attr('data-username')));
	});
	$('#outer-board-person-close').on('click', closePerson);
	$('#outer-board-person').on('click', function(e) {
		if (e.target === this) {
			closePerson();
		}
	});
	$(document).on('keydown', function(e) {
		if (e.key === 'Escape' && person !== null) {
			closePerson();
		}
	});
	root.on('click', '.uoj-ob-follow', function() {
		var username = String($(this).attr('data-username'));
		follow(username, !followed(username));
		draw(false);
		panels();
	});
	$('#outer-board-search').on('input', function() {
		looked = $.trim($(this).val()).toLowerCase();
		draw(false);
		shownRuns = 100;
		panels();
	});
	var onlyButton = function() {
		$('#outer-board-only-followed').toggleClass('uoj-ob-button-on', onlyFollowed).attr('aria-pressed', onlyFollowed ? 'true' : 'false');
	};
	onlyButton();
	$('#outer-board-only-followed').on('click', function() {
		onlyFollowed = !onlyFollowed;
		keep('only-followed', onlyFollowed ? 'on' : 'off');
		onlyButton();
		draw(false);
		shownRuns = 100;
		panels();
	});

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
		$.ajax({url: url + (wantsRuns() ? '&runs=1' : ''), data: {have: state.hash}, dataType: 'json', cache: false, timeout: 10000}).done(function(next) {
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
				panels();
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
	showTab(window.location.hash.replace('#', ''));
	tick();
	live(true);
	// the bar stands where the contest is, and moves on from there
	setTimeout(function() {
		$('#outer-board-progress').addClass('uoj-ob-progress-going');
	}, 50);
	setInterval(tick, 1000);
	// a page opened on what was submitted asks for it at once
	if (wantsRuns() && !state.runs) {
		ask();
	} else {
		later();
	}
})(jQuery);
