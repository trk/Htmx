<?php declare(strict_types=1); ?>
<div class='uk-card uk-card-default uk-card-body uk-margin' id='test2-view'>
	<h3 class='uk-card-title'>Test 2: View File</h3>
	<p>Count: <?= (int) $count ?></p>
	<form hx-action='<?= $component->requestUrl() ?>' hx-method='post' hx-target='#test2-view' hx-swap='outerMorph'>
		<?= $component->renderStatePayload() ?>
		<button type='submit' name='__htmx_action' value='increment' class='uk-button uk-button-secondary'>Increment File</button>
	</form>
</div>
