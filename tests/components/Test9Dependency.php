<?php
namespace Htmx\Component;

use Totoglu\Htmx\Component;
use ProcessWire\Page;

class Test9Dependency extends Component
{
    public ?Page $testPage = null;
    public int $clicks = 0;

    public function mount(): void
    {
        if ($this->testPage === null && $this->param('testPage') instanceof Page) {
            $this->testPage = $this->param('testPage');
        }
    }

    public function ping(): void
    {
        $this->clicks++;
    }

    public function render(): string
    {
        $title = $this->testPage ? htmlspecialchars((string) $this->testPage->title, ENT_QUOTES) : 'Unknown';

        return "
        <div class='uk-card uk-card-default uk-card-body' id='test9'>
            <h3 class='uk-card-title'>Test 9: Object Dependency</h3>
            <p>Bound to Page: <strong>{$title}</strong></p>
            <p>Pings: <strong>{$this->clicks}</strong></p>
            <form hx-post='{$this->requestUrl()}' hx-target='#test9' hx-swap='outerHTML' hx-select='#test9'>
                {$this->renderStatePayload()}
                <button type='submit' name='hx__action' value='ping' class='uk-button uk-button-default'>Ping!</button>
            </form>
        </div>";
    }
}
