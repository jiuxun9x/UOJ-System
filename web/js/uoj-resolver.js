// The resolver of an ICPC contest (contest_resolver.php), as the resolver of the ICPC Tools and
// Hydro's (XCPC-Tools) do it. The board as it froze is shown, and run down to its foot; then,
// a step at a time: the lowest of the board who still has something not told is chosen, the
// first of their problems not told starts to flash, and the next step tells what became of it
// - and if they rise, they glide up to their new place. When everybody below the chosen one is
// final, and so everybody who won a medal of a kind is, the medal is awarded, with whom it went
// to. In the end the board is final, and goes back up to its top.
(function($) {
	var root = $('#resolver');
	if (!root.length || !window.uojResolver) {
		return;
	}
	var data = window.uojResolver;
	var view = $('#resolver-view')[0];
	var list = $('#table-resolver');
	var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	$('body').addClass('uoj-rs-page');

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

	// ---- where it stands. Every cell is as the board froze until it is told.
	var fresh = function() {
		return {started: false, revealed: {}, chosen: null, flashing: null, award: null, awarded: [], finished: false};
	};
	var state = fresh();
	var taken = [];
	var cellOf = function(person, pos) {
		return person.cells[pos][state.revealed[person.key + '/' + pos] ? 1 : 0];
	};
	// the first problem of somebody that is not told yet, or -1
	var hidden = function(person) {
		for (var pos = 0; pos < person.cells.length; pos++) {
			if (!state.revealed[person.key + '/' + pos] && person.cells[pos][0][0] === 'pending') {
				return pos;
			}
		}
		return -1;
	};
	var left = function() {
		var n = 0;
		$.each(data.people, function(index, person) {
			for (var pos = 0; pos < person.cells.length; pos++) {
				if (!state.revealed[person.key + '/' + pos] && person.cells[pos][0][0] === 'pending') {
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
	// the lowest of the board who still has something not told: its place, or -1
	var lowest = function(rows) {
		for (var index = rows.length - 1; index >= 0; index--) {
			if (hidden(rows[index].person) >= 0) {
				return index;
			}
		}
		return -1;
	};
	// The kind of medal to award now, or null: the least of those that have not been awarded
	// and whose winners are all final, that is, below the lowest who has something not told.
	var awardDue = function(rows) {
		var low = lowest(rows);
		for (var kind = data.medals.length; kind >= 1; kind--) {
			if (state.awarded.indexOf(kind) >= 0) {
				continue;
			}
			var winners = 0;
			var final = true;
			$.each(rows, function(index, row) {
				if (data.medal[row.person.key] === kind) {
					winners++;
					final = final && index > low;
				}
			});
			if (winners > 0 && final) {
				return kind;
			}
		}
		return null;
	};

	// ---- the stage: a row for everybody, as high as the screen's own share of it
	var rowHeight = 80;
	var layout = function() {
		var base = window.innerWidth < 768 ? 56 : 80;
		var high = Math.max(base, view.clientHeight);
		rowHeight = high / Math.max(1, Math.floor(high / base));
		root[0].style.setProperty('--rs-row', rowHeight + 'px');
		list.css('height', data.people.length * rowHeight);
	};
	var rowsOf = {};
	var build = function() {
		$.each(data.people, function(index, person) {
			var cells = '';
			for (var pos = 0; pos < data.problems.length; pos++) {
				cells += '<span class="uoj-rs-cell"></span>';
			}
			var el = $('<div class="uoj-rs-row" />').attr({'data-key': person.key, 'data-username': person.username})
				.html('<div class="uoj-rs-rank"><span></span></div>'
					+ '<div class="uoj-rs-content"><div class="uoj-rs-name"></div><div class="uoj-rs-problems">' + cells + '</div></div>'
					+ '<div class="uoj-rs-total"></div><div class="uoj-rs-penalty"></div>');
			el.find('.uoj-rs-name').text(person.nickname !== '' ? person.nickname : person.username).attr('title', person.username);
			el.find('.uoj-rs-cell').each(function(pos) {
				$(this).attr('data-problem', data.problems[pos].letter);
			});
			rowsOf[person.key] = el;
			list.append(el);
		});
	};
	var paintCell = function(td, cell, problem) {
		td.className = 'uoj-rs-cell uoj-rs-' + cell[0];
		if (cell[0] === 'none') {
			td.textContent = problem.letter;
			return;
		}
		td.textContent = '';
		$('<b />').text(cell[1]).appendTo(td);
		if (cell[2] !== '') {
			$('<small />').text(cell[2].replace(/ \+ /g, '+')).appendTo(td);
		}
	};
	// where each row is, as a place on the board; and the rows that are to glide to theirs
	var placed = {};
	var pending = null;
	var moveTimer = null;
	var cleanTimer = null;
	var put = function(el, y, takes) {
		el.css({'transition-duration': (takes || 0) + 'ms, 0.25s', 'transform': 'translateY(' + y + 'px)'}).data('y', y);
	};
	var glide = function() {
		clearTimeout(moveTimer);
		if (pending === null) {
			return;
		}
		$.each(pending, function(at, move) {
			move[3]();
			put(move[0].addClass('uoj-rs-moving'), move[1], move[2]);
			setTimeout(function() {
				move[0].removeClass('uoj-rs-moving');
			}, move[2]);
		});
		pending = null;
	};
	// Draws the board. told: the cell that was just told ({key, pos}); then the rows that move
	// glide there, after a moment to see what it was. Returns how long that takes, in ms.
	var draw = function(told) {
		// what was about to glide goes now
		glide();
		clearTimeout(cleanTimer);
		list.find('.uoj-rs-told, .uoj-rs-bump').removeClass('uoj-rs-told uoj-rs-bump');
		var rows = standing();
		var longest = 0;
		var moves = [];
		$.each(rows, function(index, row) {
			var person = row.person;
			var el = rowsOf[person.key];
			var kind = data.medal[person.key];
			var medal = kind && (state.finished || state.awarded.indexOf(kind) >= 0) ? data.medals[kind - 1] : null;
			// (the place of a row that glides is said when it goes)
			var rank = function() {
				el.find('.uoj-rs-rank span').text(row.rank)
					.css({'background-color': medal ? medal.color : '', 'color': medal ? inkOn(medal.color) : ''})
					.attr('title', medal ? medal.name : null);
			};
			el.attr({'data-rank': row.rank, 'data-solved': row.solved, 'data-penalty': row.penalty})
				.toggleClass('uoj-rs-chosen', person.key === state.chosen);
			var tds = el.find('.uoj-rs-cell');
			for (var pos = 0; pos < data.problems.length; pos++) {
				paintCell(tds[pos], cellOf(person, pos), data.problems[pos]);
			}
			if (state.flashing !== null && person.key === state.chosen) {
				$(tds[state.flashing]).addClass('uoj-rs-uncover');
			}
			var solved = el.find('.uoj-rs-total');
			var penalty = el.find('.uoj-rs-penalty');
			if (told && told.key === person.key) {
				$(tds[told.pos]).addClass('uoj-rs-told');
				if (solved.text() !== String(row.solved)) {
					solved.add(penalty).addClass('uoj-rs-bump');
				}
			}
			solved.text(row.solved);
			penalty.text(Math.floor(row.penalty / 60));
			var was = placed[person.key];
			placed[person.key] = index;
			var y = index * rowHeight;
			if (el.data('y') === y) {
				rank();
				return;
			}
			if (!told || was === undefined || still) {
				rank();
				put(el, y);
				return;
			}
			var takes = Math.max(600, Math.min(4000, Math.abs(was - index) * 200));
			longest = Math.max(longest, takes);
			moves.push([el, y, takes, rank]);
		});
		// what was told is seen first, then the board moves
		if (moves.length) {
			pending = moves;
			moveTimer = setTimeout(glide, 700);
			longest += 700;
		}
		cleanTimer = setTimeout(function() {
			list.find('.uoj-rs-told, .uoj-rs-bump').removeClass('uoj-rs-told uoj-rs-bump');
		}, 950);

		var n = left();
		$('#resolver-left').text(state.finished ? '全部揭晓' : '待揭晓 ' + n);
		$('#resolver-count').text(state.started && !state.finished ? '待揭晓 ' + n : '');
		$('#resolver-phase').text(state.finished ? '最终榜单' : '滚榜').toggleClass('uoj-rs-pill-final', state.finished);
		$('#resolver-back').prop('disabled', taken.length === 0);
		$('#resolver-next').prop('disabled', state.finished);
		root.attr({'data-left': n, 'data-finished': state.finished ? 1 : 0, 'data-chosen': state.chosen || '',
			'data-flashing': state.flashing === null ? '' : state.flashing, 'data-award': state.award || '', 'data-steps': taken.length});
		award(rows);
		medals();
		return longest;
	};
	// a medal that is awarded: its name, in its colour, and whom it went to
	var award = function(rows) {
		var box = $('#resolver-award');
		if (state.award === null) {
			box.hide().empty();
			return;
		}
		var kind = data.medals[state.award - 1];
		var winners = $.grep(rows, function(row) {
			return data.medal[row.person.key] === state.award;
		});
		var items = $.map(winners, function(row, index) {
			var person = row.person;
			return $('<li />').css('animation-delay', Math.min(index * 0.08, 1.6) + 's')
				.append($('<b />').text(row.rank))
				.append($('<span />').text(person.nickname !== '' ? person.nickname : person.username))[0];
		});
		var card = $('<div class="uoj-rs-award-card" />')
			.append($('<div class="uoj-rs-award-name" />').text(kind.name).css({'background-color': kind.color, 'color': inkOn(kind.color)}))
			.append($('<ol class="uoj-rs-award-list" />').toggleClass('uoj-rs-award-many', winners.length > 8).append(items));
		box.empty().append(card).show();
	};
	// in the end: the kinds of medal, and how many went to each
	var medals = function() {
		var box = $('#resolver-medals').empty();
		$.each(data.medals, function(index, kind) {
			$('<span />').append($('<i />').css('background-color', kind.color)).append(document.createTextNode(kind.name + ' ' + kind.given)).appendTo(box);
		});
		box.toggle(state.finished && data.medals.length > 0);
	};

	// ---- where the stage looks: the chosen one a few rows above its foot
	var scrolling = null;
	var scrollTo = function(top, takes, linear) {
		if (scrolling) {
			cancelAnimationFrame(scrolling.frame);
			scrolling = null;
		}
		top = Math.max(0, Math.min(top, view.scrollHeight - view.clientHeight));
		var from = view.scrollTop;
		if (still || !takes || Math.abs(top - from) < 1) {
			view.scrollTop = top;
			return 0;
		}
		var began = null;
		var step = function(now) {
			if (began === null) {
				began = now;
			}
			var t = Math.min(1, (now - began) / takes);
			var eased = linear ? t : (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);
			view.scrollTop = from + (top - from) * eased;
			if (t < 1) {
				scrolling.frame = requestAnimationFrame(step);
			} else {
				scrolling = null;
			}
		};
		scrolling = {top: top, frame: requestAnimationFrame(step)};
		return takes;
	};
	var below = function(index) {
		var top = (index + 3) * rowHeight - view.clientHeight;
		return Math.ceil(top / rowHeight - 0.001) * rowHeight;
	};
	// where the stage is to look, as things stand
	var aim = function() {
		if (!state.started || state.finished) {
			return 0;
		}
		var low = lowest(standing());
		return low < 0 ? 0 : below(low);
	};

	// ---- a step
	var keep = function() {
		taken.push(JSON.stringify(state));
	};
	// Takes the next step. Returns how long what it set going takes (ms), or -1 when there is
	// nothing left to do.
	var next = function() {
		if (scrolling && scrolling.intro) {
			// the run down the board is cut short
			scrollTo(scrolling.top, 0);
			return 0;
		}
		if (state.finished) {
			return -1;
		}
		if (!state.started) {
			keep();
			state.started = true;
			draw();
			var foot = view.scrollHeight - view.clientHeight;
			var takes = scrollTo(foot, Math.max(800, Math.min(12000, (foot - view.scrollTop) * 1.5)), true);
			if (scrolling) {
				scrolling.intro = true;
			}
			return takes;
		}
		if (state.award !== null) {
			keep();
			state.award = null;
			return draw();
		}
		if (state.flashing !== null) {
			keep();
			var pos = state.flashing;
			state.revealed[state.chosen + '/' + pos] = true;
			state.flashing = null;
			return draw({key: state.chosen, pos: pos});
		}
		var rows = standing();
		var kind = awardDue(rows);
		if (kind !== null) {
			keep();
			state.award = kind;
			state.awarded.push(kind);
			state.chosen = null;
			draw();
			return 1500;
		}
		var low = lowest(rows);
		if (low < 0) {
			keep();
			state.chosen = null;
			state.finished = true;
			draw();
			scrollTo(0, Math.max(800, Math.min(6000, view.scrollTop * 0.8)));
			return -1;
		}
		var person = rows[low].person;
		if (state.chosen !== person.key) {
			keep();
			state.chosen = person.key;
			draw();
			return scrollTo(below(low), 600);
		}
		keep();
		state.flashing = hidden(person);
		draw();
		return 0;
	};
	// a step back: as it was before the last one, at once
	var back = function() {
		var was = taken.pop();
		if (was === undefined) {
			return;
		}
		state = JSON.parse(was);
		draw();
		scrollTo(aim(), 0);
	};
	var restart = function() {
		state = fresh();
		taken = [];
		auto(false);
		draw();
		scrollTo(0, 0);
	};

	// ---- by itself, a step every so often
	var playing = null;
	var auto = function(on) {
		clearTimeout(playing);
		playing = null;
		$('#resolver-auto').toggleClass('uoj-rs-on', on).text(on ? '暂停' : '自动');
		if (!on) {
			return;
		}
		var go = function() {
			var takes = next();
			if (takes < 0) {
				auto(false);
				return;
			}
			var pause = parseInt($('#resolver-speed').val(), 10) || 1200;
			playing = setTimeout(go, takes + (state.award !== null ? pause * 2 : pause));
		};
		go();
	};

	// ---- the whole screen: the stage alone
	var full = function() {
		return document.fullscreenElement === root[0] || root.hasClass('uoj-rs-full');
	};
	var relayout = function() {
		clearTimeout(moveTimer);
		$.each(pending || [], function(at, move) {
			move[3]();
		});
		pending = null;
		layout();
		$.each(rowsOf, function(key, el) {
			put(el, placed[key] * rowHeight);
		});
		scrollTo(scrolling ? scrolling.top : aim(), 0);
	};
	var toggleFull = function() {
		if (document.fullscreenElement) {
			document.exitFullscreen();
		} else if (root.hasClass('uoj-rs-full') || !root[0].requestFullscreen) {
			// where the browser has no whole screen to give, the stage takes the window
			root.toggleClass('uoj-rs-full');
			relayout();
		} else {
			root[0].requestFullscreen().catch(function() {
				root.addClass('uoj-rs-full');
				relayout();
			});
		}
	};
	$(document).on('fullscreenchange', function() {
		$('#resolver-full').text(document.fullscreenElement ? '退出全屏' : '全屏');
		relayout();
	});
	$(window).on('resize', relayout);

	// ---- the buttons and the keys
	$('#resolver-next').on('click', function() {
		this.blur();
		next();
	});
	$('#resolver-back').on('click', function() {
		this.blur();
		auto(false);
		back();
	});
	$('#resolver-restart').on('click', function() {
		this.blur();
		if (taken.length === 0 || window.confirm('回到封榜时的榜单，从头再滚一遍？')) {
			restart();
		}
	});
	$('#resolver-auto').on('click', function() {
		this.blur();
		auto(playing === null);
	});
	$('#resolver-speed').on('change', function() {
		this.blur();
		if (playing !== null) {
			auto(true);
		}
	});
	$('#resolver-full').on('click', function() {
		this.blur();
		toggleFull();
	});
	$('#resolver-stage').on('click', function() {
		if (full()) {
			next();
		}
	});
	$(document).on('keydown', function(e) {
		if (e.ctrlKey || e.metaKey || e.altKey || $(e.target).is('input, select, textarea')) {
			return;
		}
		var key = e.key;
		if (key === ' ' || key === 'Enter' || key === 'ArrowRight' || key === 'ArrowDown' || key === 'PageDown' || key === 'n') {
			e.preventDefault();
			auto(false);
			next();
		} else if (key === 'ArrowLeft' || key === 'ArrowUp' || key === 'Backspace' || key === 'PageUp' || key === 'b') {
			e.preventDefault();
			auto(false);
			back();
		} else if (key === 'a') {
			auto(playing === null);
		} else if (key === 'f') {
			toggleFull();
		} else if (key === 'r') {
			$('#resolver-restart').trigger('click');
		} else if (key === 'Escape' && root.hasClass('uoj-rs-full')) {
			toggleFull();
		}
	});

	build();
	layout();
	draw();
	scrollTo(0, 0);
	setTimeout(function() {
		root.addClass('uoj-rs-live');
	}, 50);
})(jQuery);
