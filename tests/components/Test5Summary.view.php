<?php declare(strict_types=1); ?>
<div class="uk-card uk-card-default uk-card-body" id="test5">
    <h3 class="uk-card-title">Test 5: Summary (Textarea, File View)</h3>

    <?php if ($message): ?>
        <div class="uk-alert uk-alert-success"><p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p></div>
    <?php endif; ?>

    <form hx-action="<?= $component->requestUrl() ?>" hx-method="post" hx-target="#test5" hx-swap="outerMorph">
        <?= $component->renderStatePayload() ?>

        <div class="uk-margin">
            <label class="uk-form-label">Summary Text</label>
            <div class="uk-form-controls">
                <textarea class="uk-textarea" rows="4" name="summary"><?= htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </div>

        <button type="submit" name="__htmx_action" value="save" class="uk-button uk-button-primary">Save Summary</button>
    </form>
</div>
