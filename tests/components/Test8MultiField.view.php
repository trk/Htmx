<div class="uk-card uk-card-default uk-card-body" id="test8">
    <h3 class="uk-card-title">Test 8: Multi-Field Form</h3>
    
    <?php

declare(strict_types=1);

if ($message): ?>
        <div class="uk-alert uk-alert-success"><p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p></div>
    <?php endif; ?>

    <form hx-post="<?= $component->requestUrl() ?>" hx-target="#test8" hx-swap="outerHTML" hx-select="#test8">
        <?= $component->renderStatePayload() ?>
        
        <div class="uk-margin">
            <label class="uk-form-label">Title</label>
            <div class="uk-form-controls">
                <input class="uk-input" type="text" name="title" value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>

        <div class="uk-margin">
            <label class="uk-form-label">Headline</label>
            <div class="uk-form-controls">
                <input class="uk-input" type="text" name="headline" value="<?= htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>

        <div class="uk-margin">
            <label class="uk-form-label">Summary</label>
            <div class="uk-form-controls">
                <textarea class="uk-textarea" rows="3" name="summary"><?= htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </div>

        <button type="submit" name="hx__action" value="save" class="uk-button uk-button-primary">Save All Fields</button>
    </form>
</div>
