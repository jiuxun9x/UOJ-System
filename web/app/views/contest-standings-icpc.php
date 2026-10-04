<?php
	// The board of an ICPC contest: who solved how many problems, with how much penalty, and
	// for every problem how it went. $score is what calcStandings() made of the contest, for
	// the eyes it is shown to: with $frozen, what was submitted since the board froze is
	// counted and not judged.
	$problems = array();
	foreach ($contest_data['problems'] as $problem_id) {
		$problems[] = queryProblemBrief($problem_id);
	}
	// who solved each problem first, and how many did
	$first = array();
	$solved_by = array();
	foreach ($score as $username => $cells) {
		foreach ($cells as $pos => $cell) {
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
	<table class="table table-bordered table-sm uoj-scoreboard" id="table-icpc-standings"<?= $frozen ? ' data-frozen="1"' : '' ?>>
		<thead>
			<tr>
				<th style="width:3em">#</th>
				<th class="uoj-scoreboard-name"><?= UOJLocale::get('username') ?></th>
				<th style="width:4em">通过</th>
				<th style="width:5em">罚时</th>
				<?php foreach ($problems as $pos => $problem): ?>
				<th style="width:5em" title="<?= $problem ? HTML::escape(strip_tags($problem['title'])) : '' ?>">
					<a href="/contest/<?= $contest['id'] ?>/problem/<?= $problem ? problemNumber($problem) : $contest_data['problems'][$pos] ?>"><?= chr(ord('A') + $pos % 26) ?></a>
					<br /><small class="text-muted"><?= isset($solved_by[$pos]) ? $solved_by[$pos] : 0 ?></small>
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
				<td><strong><?= $row[0] / 100 ?></strong></td>
				<td><?= floor($row[1] / 60) ?></td>
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
					<a href="/submission/<?= $cell[2] ?>" style="color:inherit"><?= $says ?></a>
					<?php endif ?>
					<?php if ($under !== ''): ?><small><?= $under ?></small><?php endif ?>
					<?php endif ?>
				</td>
				<?php endforeach ?>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<div class="text-right text-muted">
	<span class="mr-3"><span class="uoj-scoreboard"><span class="uoj-icpc-first px-2">+</span></span> 最先通过</span>
	<span class="mr-3">+2：通过，此前有 2 次未通过；下面的数字是通过时的分钟数</span>
	<span class="mr-3">-3：3 次提交都未通过</span>
	<?php if ($frozen): ?><span class="mr-3">?：封榜后有提交，“1 + 2”表示封榜前 1 次未通过、封榜后 2 次提交</span><?php endif ?>
	<?= UOJLocale::get('contests::n participants', count($standings)) ?>
</div>
