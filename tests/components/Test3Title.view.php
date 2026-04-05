<div class="uk-card uk-card-default uk-card-body" id="test3">
    <h3 class="uk-card-title">Test 3: File View (Update Title)</h3>
    
    <?php if ($message): ?>
        <div class="uk-alert uk-alert-success"><p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p></div>
    <?php endif; ?>

    <form hx-post="<?= $component->requestUrl() ?>" hx-target="#test3" hx-swap="outerHTML" hx-select="#test3">
        <?= $component->renderStatePayload() ?>
        
        <div class="uk-margin">
            <label class="uk-form-label">Page Title</label>
            <div class="uk-form-controls">
                <input class="uk-input" type="text" name="title" value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>

        <button type="submit" name="hx__action" value="save" class="uk-button uk-button-primary">Save Title</button>
    </form>
</div>
