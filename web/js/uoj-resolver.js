// The resolver of an ICPC contest (contest_resolver.php): the board as it froze, and what became
// of everything it did not tell, revealed one step at a time. As the resolver of the ICPC Tools
// does it: the lowest of the board who still has something not told is chosen, then their
// problems are revealed from the left, one a step, and the board moves; when nobody has anything
// left the medals are shown.
(function($) {
	var root = $('#resolver');
	if (!root.length || !window.uojResolver) {
		return;
	}
	var data = window.uojResolver;
	var esc = function(text) {
		return $('<i />').text(text === null || text === undefined ? '' : String(text)).html();
	};
	// Black or white, whichever reads better on a colour '#rrggbb'.
	var inkOn = function(color) {
		var m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(color);
		if (!m) {
			return '#ffffff';
		}
		var lum = function(hex) {
			var c = parseInt(hex, 16) / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
		};
		return 0.2126 * lum(m[1]) + 0.7152 * lum(m[2]) + 0.0722 * lum(m[3]) > 0.36 ? '#111111' : '#ffffff';
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

	// ---- where it stands: every cell is as the board froze until it is revealed
	var revealed = {};
	var taken = [];
	var chosen = null;
	var finished = false;
	var cellOf = function(person, pos) {
		return person.cells[pos][revealed[person.key + '/' + pos] ? 1 : 0];
	};
	var hidden = function(person) {
		for (var pos = 0; pos < person.cells.length; pos++) {
			if (!revealed[person.key + '/' + pos] && person.cells[pos][0][0] === 'pending') {
				return pos;
			}
		}
		return -1;
	};
	var left = function() {
		var n = 0;
		$.each(data.people, function(index, person) {
			for (var pos = 0; pos < person.cells.length; pos++) {
				if (!revealed[person.key + '/' + pos] && person.cells[pos][0][0] === 'pending') {
					n++;
				}
			}
		});
		return n;
	};
	// the board as it stands: more solved first, then less penalty, then by name, as calcStandings() has it
	var standing = function() {
		var rows = $.map(data.people, function(person) {
			var solved = 0;
			var penalty = 0;
			for (var pos = 0; pos < person.cells.length; pos++) {
				var cell = cellOf(person, pos);
				if (cell[3] !== null) {
					solved++;
					penalty += cell[3];
				}
			}
			return {person: person, solved: solved, penalty: penalty};
		});
		rows.sort(function(a, b) {
			if (a.solved !== b.solved) {
				return b.solved - a.solved;
			}
			if (a.penalty !== b.penalty) {
				return a.penalty - b.penalty;
			}
			return a.person.username < b.person.username ? -1 : (a.person.username > b.person.username ? 1 : 0);
		});
		$.each(rows, function(index, row) {
			var before = rows[index - 1];
			row.rank = before && before.solved === row.solved && before.penalty === row.penalty ? before.rank : index + 1;
		});
		return rows;
	};

	// ---- the table
	var head = function() {
		var html = '<tr><th class="uoj-ob-rank">排名</th><th class="uoj-ob-name">选手</th>';
		$.each(data.problems, function(pos, problem) {
			var balloon = problem.color ? ' style="box-shadow: inset 0 -5px 0 ' + esc(problem.color) + '"' : '';
			html += '<th class="uoj-ob-problem" data-problem="' + esc(problem.letter) + '"' + balloon + '><span>' + esc(problem.letter) + '</span></th>';
		});
		return html + '<th class="uoj-ob-num">通过</th><th class="uoj-ob-num">罚时</th></tr>';
	};
	var rowHtml = function(row, band) {
		var person = row.person;
		var medal = finished && data.medal[person.key] ? data.medals[data.medal[person.key] - 1] : null;
		var html = '<td class="uoj-ob-rank"><span' + (medal ? ' class="uoj-ob-medal" title="' + esc(medal.name) + '" style="background-color: ' + esc(medal.color) + '; color: ' + inkOn(medal.color) + '"' : '') + '>' + row.rank + '</span></td>';
		html += '<td class="uoj-ob-name"><span class="uoj-ob-nick">' + esc(person.nickname !== '' ? person.nickname : person.username) + '</span>'
			+ (person.nickname !== '' ? '<small>' + esc(person.username) + '</small>' : '') + '</td>';
		$.each(data.problems, function(pos, problem) {
			var cell = cellOf(person, pos);
			if (cell[0] === 'none') {
				html += '<td class="uoj-ob-cell uoj-ob-none" data-problem="' + esc(problem.letter) + '">·</td>';
				return;
			}
			var paint = cell[0] === 'first' && problem.color ? ' style="background-color: ' + esc(problem.color) + '; color: ' + inkOn(problem.color) + '"' : '';
			html += '<td class="uoj-ob-cell uoj-ob-' + cell[0] + '" data-problem="' + esc(problem.letter) + '"' + paint + '><b>' + esc(cell[1]) + '</b>'
				+ (cell[2] !== '' ? '<small>' + esc(cell[2]) + '</small>' : '') + '</td>';
		});
		html += '<td class="uoj-ob-num uoj-ob-total">' + row.solved + '</td><td class="uoj-ob-num">' + Math.floor(row.penalty / 60) + '</td>';
		return html;
	};
	// Draws the board. With `moving`, a row that has another place glides there, and the cell
	// just revealed lights up.
	var draw = function(moving, lit) {
		var table = $('#table-resolver');
		var body = table.children('tbody');
		table.children('thead').html(head());
		var before = {};
		body.children('tr').each(function() {
			before[$(this).attr('data-key')] = {el: $(this), top: this.getBoundingClientRect().top};
		});
		var rows = standing();
		var band = 0;
		var els = [];
		$.each(rows, function(index, row) {
			if (index > 0 && rows[index - 1].solved !== row.solved) {
				band = 1 - band;
			}
			var was = before[row.person.key];
			var tr = was ? was.el : $('<tr />');
			tr.attr({'data-key': row.person.key, 'data-username': row.person.username, 'data-rank': row.rank, 'data-solved': row.solved, 'data-penalty': row.penalty})
				.attr('class', 'uoj-ob-band-' + band + (row.person.key === chosen ? ' uoj-rs-chosen' : '') + (hidden(row.person) < 0 ? '' : ' uoj-rs-waiting'))
				.html(rowHtml(row, band));
			if (lit && lit.key === row.person.key) {
				tr.children('td.uoj-ob-cell').eq(lit.pos).addClass('uoj-ob-flash');
			}
			els.push(tr[0]);
		});
		body.append(els);
		if (moving) {
			$.each(els, function(index, el) {
				var was = before[el.getAttribute('data-key')];
				var by = was ? was.top - el.getBoundingClientRect().top : 0;
				if (Math.abs(by) < 1) {
					return;
				}
				el.style.transition = 'none';
				el.style.transform = 'translateY(' + by + 'px)';
				el.getBoundingClientRect();
				el.style.transition = 'transform 1.2s cubic-bezier(0.2, 0.8, 0.2, 1)';
				el.style.transform = '';
				if (by > 0) {
					$(el).addClass('uoj-ob-up');
				}
			});
			setTimeout(function() {
				body.find('.uoj-ob-flash, .uoj-ob-up').removeClass('uoj-ob-flash uoj-ob-up');
			}, 2600);
		}
		var n = left();
		$('#resolver-left').text(finished ? '全部揭晓' : '还有 ' + n + ' 个题目待揭晓');
		$('#resolver-phase').text(finished ? '最终榜单' : '滚榜中').toggleClass('uoj-ob-pill-frozen', !finished).toggleClass('uoj-ob-pill-final', finished);
		$('#resolver-back').prop('disabled', taken.length === 0);
		$('#resolver-next').prop('disabled', finished);
		root.attr({'data-left': n, 'data-finished': finished ? 1 : 0, 'data-chosen': chosen || ''});
		medals();
	};
	// in the end: what the medals are, and how many each went to
	var medals = function() {
		var html = '';
		$.each(data.medals, function(index, kind) {
			html += '<span class="uoj-ob-medal-kind"><i style="background-color: ' + esc(kind.color) + '"></i>' + esc(kind.name) + '<small>' + kind.given + ' 人</small></span>';
		});
		$('#resolver-medals').html(html).toggle(finished && data.medals.length > 0);
	};
	var bring = function(key) {
		var tr = $('#table-resolver tbody tr[data-key="' + key.replace(/"/g, '') + '"]');
		if (tr.length && tr[0].scrollIntoView) {
			tr[0].scrollIntoView({block: 'center', behavior: 'smooth'});
		}
	};

	// ---- a step: choose the lowest who has something not told, or reveal their next problem
	var next = function() {
		if (finished) {
			return false;
		}
		var rows = standing();
		var lowest = null;
		for (var index = rows.length - 1; index >= 0; index--) {
			if (hidden(rows[index].person) >= 0) {
				lowest = rows[index].person;
				break;
			}
		}
		if (lowest === null) {
			taken.push({chosen: chosen, finish: true});
			chosen = null;
			finished = true;
			draw(false);
			return false;
		}
		if (chosen !== lowest.key) {
			taken.push({chosen: chosen});
			chosen = lowest.key;
			draw(false);
			bring(chosen);
			return true;
		}
		var pos = hidden(lowest);
		taken.push({chosen: chosen, revealed: lowest.key + '/' + pos});
		revealed[lowest.key + '/' + pos] = true;
		draw(true, {key: lowest.key, pos: pos});
		setTimeout(function() {
			bring(lowest.key);
		}, 1300);
		return true;
	};
	var back = function() {
		var step = taken.pop();
		if (!step) {
			return;
		}
		if (step.revealed) {
			delete revealed[step.revealed];
		}
		if (step.finish) {
			finished = false;
		}
		chosen = step.chosen;
		draw(true);
	};
	var restart = function() {
		revealed = {};
		taken = [];
		chosen = null;
		finished = false;
		auto(false);
		draw(true);
		window.scrollTo(0, 0);
	};

	// ---- by itself, every so often
	var playing = null;
	var auto = function(on) {
		clearInterval(playing);
		playing = null;
		$('#resolver-auto').toggleClass('uoj-ob-button-on', on).text(on ? '暂停' : '自动');
		if (on) {
			playing = setInterval(function() {
				if (!next()) {
					auto(false);
				}
			}, parseInt($('#resolver-speed').val(), 10) || 1500);
		}
	};
	$('#resolver-next').on('click', function() {
		next();
	});
	$('#resolver-back').on('click', back);
	$('#resolver-restart').on('click', function() {
		if (taken.length === 0 || window.confirm('回到封榜时的榜单，从头再滚一遍？')) {
			restart();
		}
	});
	$('#resolver-auto').on('click', function() {
		auto(playing === null);
	});
	$('#resolver-speed').on('change', function() {
		if (playing !== null) {
			auto(true);
		}
	});
	$(document).on('keydown', function(e) {
		if ($(e.target).is('input, select, textarea, button')) {
			return;
		}
		if (e.key === ' ' || e.key === 'Enter' || e.key === 'ArrowRight' || e.key === 'PageDown') {
			e.preventDefault();
			next();
		} else if (e.key === 'ArrowLeft' || e.key === 'Backspace' || e.key === 'PageUp') {
			e.preventDefault();
			back();
		}
	});

	// ---- for a wall: dark, and the whole screen, as the outer board
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
	if (!document.documentElement.requestFullscreen) {
		$('#outer-board-full').hide();
	}
	$('#outer-board-full').on('click', function() {
		if (document.fullscreenElement) {
			document.exitFullscreen();
		} else {
			document.documentElement.requestFullscreen();
		}
	});
	$(document).on('fullscreenchange', function() {
		$('#outer-board-full').text(document.fullscreenElement ? '退出全屏' : '全屏');
	});

	draw(false);
})(jQuery);
