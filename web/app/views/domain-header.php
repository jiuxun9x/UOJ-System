<?php
	// What a page of a domain begins with, under the bar of the domain (views/domain-nav.php):
	// that the domain is archived, where it is, and what the page before left to be said.
	$flash = domainTakeFlash();
?>
<?php if ($domain['archived_at'] !== null): ?>
<div class="alert alert-warning py-2" role="alert" id="domain-archived">这个域已归档，只能查看。</div>
<?php endif ?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0] ?> alert-dismissible" role="alert">
	<?= HTML::escape($flash[1]) ?>
	<button type="button" class="close" data-dismiss="alert" aria-label="关闭"><span aria-hidden="true">&times;</span></button>
</div>
<?php endif ?>
