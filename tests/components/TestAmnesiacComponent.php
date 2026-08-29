<?php

declare(strict_types=1);

namespace Htmx\Component;

use Totoglu\Htmx\Component;

class TestAmnesiacComponent extends Component 
{
    public int $counter = 0;
    
    public function mount(): void 
    {
        $this->counter = 10;
    }
    
    public function fill(array $props): self 
    {
        if (isset($props['start'])) {
            $this->counter = (int) $props['start'];
        }
        
        return $this;
    }

    public function increment(): void 
    {
        $this->counter++;
        
        $this->htmx->response->trigger('countUpdated', ['counter' => $this->counter]);
        
        $msg = htmlspecialchars($this->__('Counter is now'), ENT_QUOTES, 'UTF-8');
        $this->htmx->fragment->addOobSwap('#some-other-div', "<div id='some-other-div'>{$msg}: {$this->counter}</div>");
    }

    public function render(): string 
    {
        $vals = json_encode(['hx__action' => 'increment']);
        $currentCount = (int) $this->counter;
        $buttonLabel = htmlspecialchars($this->__('Increment'), ENT_QUOTES, 'UTF-8');
        
        return <<<HTML
        <div id="hxc_{$this->id()}">
            Current count: {$currentCount}
            
            <form hx-post="{$this->requestUrl()}" hx-target="#hxc_{$this->id()}" hx-swap="outerHTML" hx-vals='{$vals}'>
                {$this->renderStatePayload()}
                <button type="submit" class="uk-button uk-button-primary">
                    {$buttonLabel}
                </button>
            </form>
        </div>
        HTML;
    }
}
