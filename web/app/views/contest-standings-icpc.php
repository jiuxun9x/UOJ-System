<?php
	// The board of an ICPC contest: who solved how many problems, with how much penalty, and
	// for every problem how it went. $score is what calcStandings() made of the contest, for
	// the eyes it is shown to: with $frozen, what was submitted since the board froze is
	// counted and not judged.
	$problems = array();
	foreach ($contest_data['problems'] as $problem_id) {
		$problems[] = queryProblemBrief($problem_id);
	}
	// for every problem: who solved it first, how many solved it and how many tried
	$first = array();
	$solved_by = array();
	$tried_by = array();
	foreach ($score as $username => $cells) {
		// who sat the contest virtually afterwards solved nothing first, and is not counted
		if (strncmp((string)$username, 'v/', 2) === 0) {
			continue;
		}
		foreach ($cells as $pos => $cell) {
			$tried_by[$pos] = isset($tried_by[$pos]) ? $tried_by[$pos] + 1 : 1;
			if ($cell[0] != 100) {
				continue;
			}
			$solved_at = $cell[1] - CONTEST_ICPC_PENALTY * (isset($cell[3]) ? $cell[3] : 0);
			$solved_by[$pos] = isset($solved_by[$pos]) ? $solved_by[$pos] + 1 : 1;
			if (!isset($first[$pos]) || $solved_at < $first[$pos][0]) {
				$first[$pos] = array($solved_at, (string)$username);
			}
		}
	}
	// a cell of the board: how it went for somebody on a problem
	$print_cell = function($cell, $pos, $is_first) {
		list($says, $under, $class) = contestIcpcCell($cell);
		if ($class === 'uoj-icpc-solved' && $is_first) {
			$class = 'uoj-icpc-first';
		}
		echo '<td class="', $class, '" data-problem="', chr(ord('A') + $pos % 26), '">';
		if ($says !== '') {
			echo $class === 'uoj-icpc-pending' ? $says : '<a href="/submission/' . $cell[2] . '">' . $says . '</a>';
			if ($under !== '') {
				echo '<div class="uoj-icpc-under">', $under, '</div>';
			}
		}
		echo '</td>';
	};
	$me = Auth::check() ? Auth::id() : null;
	$mine = isset($mine) ? $mine : null;
?>
<?php if ($mine): ?>
<?php // While the board is frozen a contestant is shown how it really stands with them, as DOMjudge does: they were told the outcome of every one of their submissions. Where that puts them among the others nobody knows yet. ?>
<div class="table-responsive" id="standings-mine">
	<table class="table table-bordered uoj-scoreboard uoj-icpc-board mb-1">
		<thead>
			<tr>
				<th style="width:4em">#</th>
				<th class="uoj-scoreboard-name">我的实际成绩</th>
				<th style="width:6em">通过<div class="uoj-icpc-under">罚时</div></th>
				<?php foreach ($problems as $pos => $problem): ?>
				<th style="width:5.5em"><?= chr(ord('A') + $pos % 26) ?></th>
				<?php endforeach ?>
			</tr>
		</thead>
		<tbody>
			<tr class="uoj-scoreboard-me" data-username="<?= $me ?>" data-solved="<?= $mine['row'][0] / 100 ?>" data-penalty="<?= $mine['row'][1] ?>">
				<td title="封榜期间不显示名次">?</td>
				<td class="uoj-scoreboard-name"><?= getUserLink($me) ?></td>
				<td><span class="<?= $mine['row'][0] > 0 ? 'uoj-icpc-total' : 'uoj-icpc-total-none' ?>"><?= $mine['row'][0] / 100 ?></span><div class="uoj-icpc-under"><?= contestClock($mine['row'][1]) ?></div></td>
				<?php foreach ($problems as $pos => $problem): ?>
				<?php $print_cell(isset($mine['cells'][$pos]) ? $mine['cells'][$pos] : null, $pos, false) ?>
				<?php endforeach ?>
			</tr>
		</tbody>
	</table>
</div>
<p class="text-muted small mb-3">上面一行是你自己的实际成绩，只有你能看到；下面的榜单是封榜时的样子，你在榜上的那一行也一样。</p>
<?php endif ?>
<div class="table-responsive">
	<table class="table table-bordered table-striped uoj-scoreboard uoj-icpc-board" id="table-icpc-standings"<?= $frozen ? ' data-frozen="1"' : '' ?>>
		<thead>
			<tr>
				<th style="width:4em">#</th>
				<th class="uoj-scoreboard-name"><?= UOJLocale::get('username') ?></th>
				<th style="width:6em">通过<div class="uoj-icpc-under">罚时</div></th>
				<?php foreach ($problems as $pos => $problem): ?>
				<th style="width:5.5em"<?= isset($solved_by[$pos]) ? ' class="uoj-icpc-solved-by"' : '' ?> title="<?= $problem ? HTML::escape(strip_tags($problem['title'])) : '' ?>">
					<a href="/contest/<?= $contest['id'] ?>/problem/<?= chr(ord('A') + $pos % 26) ?>"><?= chr(ord('A') + $pos % 26) ?></a>
					<div class="uoj-icpc-under" data-solved-by="<?= isset($solved_by[$pos]) ? $solved_by[$pos] : 0 ?>"><?= isset($solved_by[$pos]) ? $solved_by[$pos] : 0 ?>/<?= isset($tried_by[$pos]) ? $tried_by[$pos] : 0 ?></div>
				</th>
				<?php endforeach ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($standings as $row): ?>
			<?php $username = (string)$row[2][0]; ?>
			<?php $is_virtual = isset($row[2][3]) && $row[2][3] === 'v'; ?>
			<?php $cells_key = $is_virtual ? 'v/' . $username : $username; ?>
			<tr<?= $username === $me ? ' class="uoj-scoreboard-me"' : '' ?> data-username="<?= $username ?>"<?= $is_virtual ? ' data-virtual="1"' : '' ?> data-rank="<?= $row[3] ?>" data-solved="<?= $row[0] / 100 ?>" data-penalty="<?= $row[1] ?>">
				<td><?= $is_virtual ? '<span class="text-muted" title="赛后虚拟参赛：放在正式比赛里是第 ' . $row[3] . ' 名">(' . $row[3] . ')</span>' : $row[3] ?></td>
				<td class="uoj-scoreboard-name"><span class="uoj-username" data-rating="<?= (int)$row[2][1] ?>"<?= isset($row[2][2]) && $row[2][2] !== '' ? ' data-alias="' . HTML::escape($row[2][2]) . '"' : '' ?>><?= $username ?></span><?= $is_virtual ? ' <span class="badge badge-info">虚拟</span>' : '' ?></td>
				<td><span class="<?= $row[0] > 0 ? 'uoj-icpc-total' : 'uoj-icpc-total-none' ?>"><?= $row[0] / 100 ?></span><div class="uoj-icpc-under"><?= contestClock($row[1]) ?></div></td>
				<?php foreach ($problems as $pos => $problem): ?>
				<?php $print_cell(isset($score[$cells_key][$pos]) ? $score[$cells_key][$pos] : null, $pos, !$is_virtual && isset($first[$pos]) && $first[$pos][1] === $username) ?>
				<?php endforeach ?>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<div class="text-right text-muted">
	<small class="mr-3">表头：通过人数/尝试人数</small>
	<small class="mr-3"><strong class="text-success">+2</strong> 通过，此前 2 次未通过；下面是通过的时间</small>
	<small class="mr-3"><strong class="text-danger">-3</strong> 交了 3 次都没通过</small>
	<small class="mr-3"><span class="uoj-scoreboard"><span class="uoj-icpc-first px-2">+</span></span> 最先通过</small>
	<small class="mr-3"><span class="uoj-scoreboard"><span class="uoj-icpc-pending px-2">?</span></span> 有还不知道结果的提交<?= $frozen ? '（封榜后交的，或正在评测）' : '（正在评测）' ?>：“1 + 2”是 1 次未通过、2 次结果未知</small>
	<?php
		$n_contestants = 0;
		foreach ($standings as $row) {
			$n_contestants += isset($row[2][3]) && $row[2][3] === 'v' ? 0 : 1;
		}
	?>
	<?= UOJLocale::get('contests::n participants', $n_contestants) ?>
</div>
