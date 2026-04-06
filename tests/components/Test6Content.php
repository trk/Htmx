<?php

declare(strict_types=1);

namespace Htmx\Component;

use Totoglu\Htmx\Component;

class Test6Content extends Component
{
    public int $pageId = 0;
    public string $content = '';
    public string $message = '';

    protected mixed $view = __DIR__ . '/Test6Content.view.php';

    public function mount(): void
    {
        if ($this->pageId && !$this->content) {
            $p = $this->wire('pages')->get($this->pageId);
            if ($p->id && $p->hasField('content')) {
                $this->content = $p->get('content');
            }
        }
    }

    public function save(): void
    {
        // we use markup payload as tinymce sends raw html
        $newContent = $this->wire('input')->post('content', 'markup');
        
        $p = $this->wire('pages')->get($this->pageId);
        if ($p->id && $p->hasField('content')) {
            $p->of(false);
            $p->content = $newContent;
            $p->save('content');
            
            $this->content = $newContent;
            $this->message = "Content block (HTML) saved successfully!";
        } else {
            $this->message = "Error: Invalid page or missing content field.";
        }
    }
}
