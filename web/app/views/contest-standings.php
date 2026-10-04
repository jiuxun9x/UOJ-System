<div id="standings"></div>

<div class="table-responsive">
	<table id="standings-table" class="table table-bordered table-striped table-text-center table-vertical-middle"></table>
</div>

<?php
	// what the addresses of the problems in this contest say: in a contest of a domain, the
	// numbers the problems have in the domain
	$problem_numbers = array();
	foreach ($contest_data['problems'] as $problem_id) {
		$problem = queryProblemBrief($problem_id);
		$problem_numbers[] = $problem ? problemNumber($problem) : (int)$problem_id;
	}
?>
<script type="text/javascript">
standings_version=<?=$contest['extra_config']['standings_version']?>;
contest_id=<?=$contest['id']?>;
standings=<?=json_encode($standings)?>;
score=<?=json_encode($score)?>;
problems=<?=json_encode($problem_numbers)?>;
$(document).ready(showStandings());
</script>
