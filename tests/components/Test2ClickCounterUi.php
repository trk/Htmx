<?php

namespace Htmx\Component;

use Totoglu\Htmx\Component;
use Totoglu\Htmx\Ui;
use Htmx\Ui\Button;

class ClickCounterUiView extends Ui
{
    public function render(): string
    {
        $payload = $this->renderState();
        $count = $this->component ? $this->component->count : 0;
        $url = $this->component ? $this->component->requestUrl() : '';

        // Our new button component in action
        $btnIncrement = (new Button(['label' => '+1', 'variant' => 'primary']))->action('increment');
        $btnDecrement = (new Button(['label' => '-1', 'variant' => 'danger']))->action('decrement');

        return "
        <div class='uk-card uk-card-default uk-card-body' id='test2'>
            <h3 class='uk-card-title'>Test 2: UI Object Counter</h3>
            <p>Count is: <strong>{$count}</strong></p>
            <form hx-post='{$url}' hx-target='#test2' hx-swap='outerHTML' hx-select='#test2'>
                {$payload}
                {$btnIncrement}
                {$btnDecrement}
            </form>
        </div>";
    }
}

class Test2ClickCounterUi extends Component
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function decrement(): void
    {
        $this->count--;
    }

    protected function beforeRender(): void
    {
        $this->view = new ClickCounterUiView();
    }
}
