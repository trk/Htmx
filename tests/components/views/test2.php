<?php declare(strict_types=1); ?>
<div class='uk-card uk-card-default uk-card-body uk-margin'>
    <h3 class='uk-card-title'>Test 2: View File</h3>
    <p>Count: <?= $this->count ?></p>
    <form hx-action='<?= $this->requestUrl() ?>' hx-method='post'>
        <?= $this->renderStatePayload() ?>
        <input type='hidden' name='__htmx_action' value='increment'>
        <button type='submit' class='uk-button uk-button-secondary'>Increment File</button>
    </form>
</div>
