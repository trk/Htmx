<?php
namespace Htmx\Component;

use Totoglu\Htmx\Component;

class Test5Summary extends Component
{
    public int $pageId = 0;
    public string $summary = '';
    public string $message = '';

    protected mixed $view = __DIR__ . '/Test5Summary.view.php';

    public function mount(): void
    {
        if ($this->pageId && !$this->summary) {
            $p = $this->wire('pages')->get($this->pageId);
            if ($p->id && $p->hasField('summary')) {
                $this->summary = $p->get('summary');
            }
        }
    }

    public function save(): void
    {
        $newSummary = $this->wire('input')->post('summary', 'textarea');
        
        $p = $this->wire('pages')->get($this->pageId);
        if ($p->id && $p->hasField('summary')) {
            $p->of(false);
            $p->summary = $newSummary;
            $p->save('summary');
            
            $this->summary = $newSummary;
            $this->message = "Summary textarea saved!";
        } else {
            $this->message = "Error: Invalid page or missing summary field.";
        }
    }
}
