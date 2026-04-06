<div class="uk-card uk-card-default uk-card-body" id="test6">
    <h3 class="uk-card-title">Test 6: Content (HTML Textarea, File View)</h3>
    
    <?php

declare(strict_types=1);

if ($message): ?>
        <div class="uk-alert uk-alert-success"><p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p></div>
    <?php endif; ?>

    <form hx-post="<?= $component->requestUrl() ?>" hx-target="#test6" hx-swap="outerHTML" hx-select="#test6">
        <?= $component->renderStatePayload() ?>
        
        <div class="uk-margin">
            <label class="uk-form-label">Content (HTML)</label>
            <div class="uk-form-controls">
                <!-- We use htmlspecialchars without removing tags so editing raw html works for testing -->
                <textarea class="uk-textarea" rows="6" name="content"><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </div>

        <button type="submit" name="hx__action" value="save" class="uk-button uk-button-primary">Save Content</button>
    </form>
</div>
