<div class="uk-card uk-card-default uk-card-body" id="test5">
    <h3 class="uk-card-title">Test 5: Summary (Textarea, File View)</h3>
    
    <?php

declare(strict_types=1);

if ($message): ?>
        <div class="uk-alert uk-alert-success"><p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p></div>
    <?php endif; ?>

    <form hx-post="<?= $component->requestUrl() ?>" hx-target="#test5" hx-swap="outerHTML" hx-select="#test5">
        <?= $component->renderStatePayload() ?>
        
        <div class="uk-margin">
            <label class="uk-form-label">Summary Text</label>
            <div class="uk-form-controls">
                <textarea class="uk-textarea" rows="4" name="summary"><?= htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </div>

        <button type="submit" name="hx__action" value="save" class="uk-button uk-button-primary">Save Summary</button>
    </form>
</div>
