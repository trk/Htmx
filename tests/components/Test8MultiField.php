<?php

declare(strict_types=1);

namespace Htmx\Component;

use Totoglu\Htmx\Component;

class Test8MultiField extends Component
{
    public int $pageId = 0;
    public string $title = '';
    public string $headline = '';
    public string $summary = '';
    public string $message = '';

    protected mixed $view = __DIR__ . '/Test8MultiField.view.php';

    public function mount(): void
    {
        if ($this->pageId && !$this->title) {
            $p = $this->wire('pages')->get($this->pageId);
            if ($p->id) {
                $this->title = $p->title;
                
                if ($p->hasField('headline')) {
                    $this->headline = $p->get('headline') ?: '';
                }
                if ($p->hasField('summary')) {
                    $this->summary = $p->get('summary') ?: '';
                }
            }
        }
    }

    public function save(): void
    {
        $input = $this->wire('input');
        $newTitle = $input->post('title', 'text');
        $newHeadline = $input->post('headline', 'text');
        $newSummary = $input->post('summary', 'textarea');
        
        $p = $this->wire('pages')->get($this->pageId);
        if ($p->id) {
            $p->of(false);
            
            if ($newTitle) {
                $p->title = $newTitle;
                $p->save('title');
            }
            if ($p->hasField('headline')) {
                $p->headline = $newHeadline;
                $p->save('headline');
            }
            if ($p->hasField('summary')) {
                $p->summary = $newSummary;
                $p->save('summary');
            }
            
            $this->title = $newTitle;
            $this->headline = $newHeadline;
            $this->summary = $newSummary;
            
            $this->message = "All fields successfully updated!";
        } else {
            $this->message = "Error saving fields.";
        }
    }
}
