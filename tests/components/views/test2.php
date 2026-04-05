<div class='uk-card uk-card-default uk-card-body uk-margin'>
    <h3 class='uk-card-title'>Test 2: View File</h3>
    <p>Count: <?= $this->count ?></p>
    <form method='POST' hx-post='<?= $this->requestUrl() ?>'>
        <?= $this->renderStatePayload() ?>
        <input type='hidden' name='hx__action' value='increment'>
        <button type='submit' class='uk-button uk-button-secondary'>Increment File</button>
    </form>
</div>
