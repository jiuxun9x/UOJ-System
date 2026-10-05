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
?>
<div class="table-responsive">
	<table class="table table-bordered table-striped uoj-scoreboard uoj-icpc-board" id="table-icpc-standings"<?= $frozen ? ' data-frozen="1"' : '' ?>>
		<thead>
			<tr>
				<th style="width:4em">#</th>
				<th class="uoj-scoreboard-name"><?= UOJLocale::get('username') ?></th>
				<th style="width:6em">通过<div class="uoj-icpc-under">罚时</div></th>
				<?php foreach ($problems as $pos => $problem): ?>
				<th style="width:5.5em"<?= isset($solved_by[$pos]) ? ' class="uoj-icpc-solved-by"' : '' ?> title="<?= $problem ? HTML::escape(strip_tags($problem['title'])) : '' ?>">
					<a href="/contest/<?= $contest['id'] ?>/problem/<?= $problem ? problemNumber($problem) : $contest_data['problems'][$pos] ?>"><?= chr(ord('A') + $pos % 26) ?></a>
					<div class="uoj-icpc-under" data-solved-by="<?= isset($solved_by[$pos]) ? $solved_by[$pos] : 0 ?>"><?= isset($solved_by[$pos]) ? $solved_by[$pos] : 0 ?>/<?= isset($tried_by[$pos]) ? $tried_by[$pos] : 0 ?></div>
				</th>
				<?php endforeach ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($standings as $row): ?>
			<?php $username = (string)$row[2][0]; ?>
			<tr data-username="<?= $username ?>" data-rank="<?= $row[3] ?>" data-solved="<?= $row[0] / 100 ?>" data-penalty="<?= $row[1] ?>">
				<td><?= $row[3] ?></td>
				<td class="uoj-scoreboard-name"><span class="uoj-username" data-rating="<?= (int)$row[2][1] ?>"<?= isset($row[2][2]) && $row[2][2] !== '' ? ' data-alias="' . HTML::escape($row[2][2]) . '"' : '' ?>><?= $username ?></span></td>
				<td><span class="<?= $row[0] > 0 ? 'uoj-icpc-total' : 'uoj-icpc-total-none' ?>"><?= $row[0] / 100 ?></span><div class="uoj-icpc-under"><?= contestClock($row[1]) ?></div></td>
				<?php foreach ($problems as $pos => $problem): ?>
				<?php
					$cell = isset($score[$username][$pos]) ? $score[$username][$pos] : null;
					list($says, $under, $class) = contestIcpcCell($cell);
					if ($class === 'uoj-icpc-solved' && isset($first[$pos]) && $first[$pos][1] === $username) {
						$class = 'uoj-icpc-first';
					}
				?>
				<td class="<?= $class ?>" data-problem="<?= chr(ord('A') + $pos % 26) ?>">
					<?php if ($says !== ''): ?>
					<?php if ($class === 'uoj-icpc-pending'): ?>
					<?= $says ?>
					<?php else: ?>
					<a href="/submission/<?= $cell[2] ?>"><?= $says ?></a>
					<?php endif ?>
					<?php if ($under !== ''): ?><div class="uoj-icpc-under"><?= $under ?></div><?php endif ?>
					<?php endif ?>
				</td>
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
	<?php if ($frozen): ?><small class="mr-3"><span class="uoj-scoreboard"><span class="uoj-icpc-pending px-2">?</span></span> 封榜后有提交：“1 + 2”是封榜前 1 次未通过、封榜后交了 2 次</small><?php endif ?>
	<?= UOJLocale::get('contests::n participants', count($standings)) ?>
</div>
