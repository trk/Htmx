<div class='uk-card uk-card-default uk-card-body uk-margin'>
    <h3 class='uk-card-title'>Test 8: Update Multiple Fields</h3>
    <form method='POST' hx-post='<?= $this->requestUrl() ?>'>
        <?= $this->renderStatePayload() ?>
        <input type='hidden' name='hx__action' value='saveAll'>
        
        <div class='uk-margin'>
            <label class='uk-form-label'>Page Title</label>
            <div class='uk-form-controls'>
                <input class='uk-input' type='text' name='title' value='<?= htmlspecialchars($this->title) ?>'>
            </div>
        </div>

        <div class='uk-margin'>
            <label class='uk-form-label'>Headline</label>
            <div class='uk-form-controls'>
                <input class='uk-input' type='text' name='headline' value='<?= htmlspecialchars($this->headline) ?>'>
            </div>
        </div>

        <button type='submit' class='uk-button uk-button-primary'>Save All Fields</button>
    </form>
</div>
