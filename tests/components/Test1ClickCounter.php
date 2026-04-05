<?php

namespace Htmx\Component;

use Totoglu\Htmx\Component;

class Test1ClickCounter extends Component
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

    public function render(): string
    {
        // Pure inline render testing state tracker directly
        $url = $this->requestUrl();
        $payload = $this->renderStatePayload();
        $disabled = $this->count === 0 ? ' disabled' : '';

        return "
        <div class='uk-card uk-card-default uk-card-body' id='test1'>
            <h3 class='uk-card-title'>Test 1: Inline Counter</h3>
            <p>Count is: <strong>{$this->count}</strong></p>
            <form hx-post='{$url}' hx-target='#test1' hx-swap='outerHTML' hx-select='#test1'>
                {$payload}
                <button type='submit' name='hx__action' value='increment' class='uk-button uk-button-primary'>+1</button>
                <button type='submit' name='hx__action' value='decrement' class='uk-button uk-button-danger'{$disabled}>-1</button>
            </form>
        </div>";
    }
}
