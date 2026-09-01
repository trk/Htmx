<?php declare(strict_types=1); ?>
<div class="uk-card uk-card-default uk-card-body" id="test3">
    <h3 class="uk-card-title">Test 3: File View (Update Title)</h3>

    <?php if ($message): ?>
        <div class="uk-alert uk-alert-success"><p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p></div>
    <?php endif; ?>

    <form hx-action="<?= $component->requestUrl() ?>" hx-method="post" hx-target="#test3" hx-swap="outerMorph">
        <?= $component->renderStatePayload() ?>

        <div class="uk-margin">
            <label class="uk-form-label">Page Title</label>
            <div class="uk-form-controls">
                <input class="uk-input" type="text" name="title" value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>

        <button type="submit" name="__htmx_action" value="save" class="uk-button uk-button-primary">Save Title</button>
    </form>
</div>
