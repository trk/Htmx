<?php

declare(strict_types=1);

namespace Totoglu\Htmx;

class TestAmnesiacComponent extends Component 
{
    // NO private/protected state! 
    public int $counter = 0;
    
    // Mount instead of __construct
    public function mount(): void 
    {
        $this->counter = 10;
    }
    
    // Fill instead of __construct for parsing inputs
    public function fill(array $props): self 
    {
        if (isset($props['start'])) {
            $this->counter = (int) $props['start'];
        }
        
        return $this;
    }

    // Public method, acting as an action
    // NO setState() usage! Direct mutation of public property!
    public function increment(): void 
    {
        $this->counter++;
        
        // Demonstrate HTMX Response API: trigger a custom event globally
        $this->htmx->response->trigger('countUpdated', ['counter' => $this->counter]);
        
        // Demonstrate HTMX Fragment API: out-of-band swap to update another element automatically
        $msg = $this->_('Counter is now');
        $this->htmx->fragment->addOobSwap('#some-other-div', "<div id='some-other-div'>{$msg}: {$this->counter}</div>");
    }

    public function render(): string 
    {
        return <<<HTML
        <div id="test-component" {$this->attributes->render()}>
            <!-- Direct access to public property state -->
            Current count: {$this->counter}
            
            <button 
                hx-post="{$this->action('increment')}" 
                hx-target="#test-component">
                {$this->_('Increment')}
            </button>
            
            <!-- Output raw state payload for automatic signing logic -->
            {$this->renderStatePayload()}
        </div>
        HTML;
    }
}
