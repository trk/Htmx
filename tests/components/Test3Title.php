<?php

declare(strict_types=1);

namespace Htmx\Component;

use Totoglu\Htmx\Component;

class Test3Title extends Component
{
    public int $pageId = 0;
    public string $title = '';
    public string $message = '';

    protected mixed $view = __DIR__ . '/Test3Title.view.php';

    public function mount(): void
    {
        if ($this->pageId && !$this->title) {
            $p = $this->wire('pages')->get($this->pageId);
            if ($p->id) {
                $this->title = $p->title;
            }
        }
    }

    public function save(): void
    {
        $newTitle = $this->wire('input')->post('title', 'text');
        
        $p = $this->wire('pages')->get($this->pageId);
        if ($p->id && $newTitle) {
            $p->of(false);
            $p->title = $newTitle;
            $p->save('title');
            
            $this->title = $newTitle;
            $this->message = "Title successfully saved!";
        } else {
            $this->message = "Error: Invalid input or page.";
        }
    }
}
