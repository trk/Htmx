<?php declare(strict_types=1); ?>
<div class='uk-card uk-card-default uk-card-body uk-margin' id='test8-view'>
	<h3 class='uk-card-title'>Test 8: Update Multiple Fields</h3>
	<form hx-action='<?= $component->requestUrl() ?>' hx-method='post' hx-target='#test8-view' hx-swap='outerMorph'>
		<?= $component->renderStatePayload() ?>

		<div class='uk-margin'>
			<label class='uk-form-label'>Page Title</label>
			<div class='uk-form-controls'>
				<input class='uk-input' type='text' name='title' value='<?= htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8') ?>'>
			</div>
		</div>

		<div class='uk-margin'>
			<label class='uk-form-label'>Headline</label>
			<div class='uk-form-controls'>
				<input class='uk-input' type='text' name='headline' value='<?= htmlspecialchars((string) $headline, ENT_QUOTES, 'UTF-8') ?>'>
			</div>
		</div>

		<button type='submit' name='__htmx_action' value='saveAll' class='uk-button uk-button-primary'>Save All Fields</button>
	</form>
</div>
