<?php

declare(strict_types=1);

namespace Htmx\Component;

use Totoglu\Htmx\Component;
use Totoglu\Htmx\Ui;
use Htmx\Ui\Button;

class ButtonTestUiView extends Ui
{
    public function render(): string
    {
        $payload = $this->renderState();
        $clicks = $this->component ? $this->component->clicks : 0;
        $url = $this->component ? $this->component->requestUrl() : '';

        $btnLike = (new Button(['label' => 'Like', 'variant' => 'success']))->action('like');
        $btnReset = (new Button(['label' => 'Reset', 'variant' => 'danger']))->action('reset');

        return "
        <div class='uk-card uk-card-default uk-card-body' id='test7'>
            <h3 class='uk-card-title'>Test 7: Custom Button Component</h3>
            <p>Likes: <strong>{$clicks}</strong></p>
            <form hx-post='{$url}' hx-target='#test7' hx-swap='outerHTML' hx-select='#test7'>
                {$payload}
                {$btnLike}
                {$btnReset}
            </form>
        </div>";
    }
}

class Test7Button extends Component
{
    public int $clicks = 0;

    // initialize logic removed. Value starts at 0 automatically due to `public int $clicks = 0;`.

    protected function beforeRender(): void
    {
        $this->view = new ButtonTestUiView();
    }

    public function like(): void
    {
        $this->clicks++;
    }

    public function reset(): void
    {
        $this->clicks = 0;
    }
}
