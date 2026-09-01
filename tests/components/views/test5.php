<?php declare(strict_types=1); ?>
<div class='uk-card uk-card-default uk-card-body uk-margin'>
    <h3 class='uk-card-title'>Test 5: Update Headline (Page <?= $this->pageId ?>)</h3>
    <form hx-action='<?= $this->requestUrl() ?>' hx-method='post'>
        <?= $this->renderStatePayload() ?>
        <input type='hidden' name='__htmx_action' value='saveHeadline'>
        <div class='uk-margin'>
            <label class='uk-form-label'>Headline</label>
            <div class='uk-form-controls'>
                <input class='uk-input' type='text' name='headline' value='<?= htmlspecialchars($this->headline) ?>'>
            </div>
        </div>
        <button type='submit' class='uk-button uk-button-secondary'>Save Headline</button>
    </form>
</div>
