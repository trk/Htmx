<?php declare(strict_types=1); ?>
<div class="uk-card uk-card-default uk-card-body" id="test6">
    <h3 class="uk-card-title">Test 6: Content (HTML Textarea, File View)</h3>

    <?php if ($message): ?>
        <div class="uk-alert uk-alert-success"><p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p></div>
    <?php endif; ?>

    <form hx-action="<?= $component->requestUrl() ?>" hx-method="post" hx-target="#test6" hx-swap="outerMorph">
        <?= $component->renderStatePayload() ?>

        <div class="uk-margin">
            <label class="uk-form-label">Content (HTML)</label>
            <div class="uk-form-controls">
                <textarea class="uk-textarea" rows="6" name="content"><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </div>

        <button type="submit" name="__htmx_action" value="save" class="uk-button uk-button-primary">Save Content</button>
    </form>
</div>
