<?php

namespace Htmx\Component;

use Totoglu\Htmx\Component;
use Totoglu\Htmx\Ui;
use Htmx\Ui\Button;

class HeadlineUiView extends Ui
{
    public function render(): string
    {
        $payload = $this->renderState();
        $headline = $this->component ? $this->component->headline : '';
        $message = $this->component ? $this->component->message : '';
        $url = $this->component ? $this->component->requestUrl() : '';

        $btnSave = (new Button(['label' => 'Save Headline', 'variant' => 'secondary']))->action('save');

        $msgHtml = $message ? "<div class='uk-alert uk-alert-success'><p>{$this->esc($message)}</p></div>" : '';

        return "
        <div class='uk-card uk-card-default uk-card-body' id='test4'>
            <h3 class='uk-card-title'>Test 4: UI Object (Update Headline)</h3>
            {$msgHtml}
            <form hx-post='{$url}' hx-target='#test4' hx-swap='outerHTML' hx-select='#test4'>
                {$payload}
                
                <div class='uk-margin'>
                    <label class='uk-form-label'>Headline</label>
                    <div class='uk-form-controls'>
                        <input class='uk-input' type='text' name='headline' value='{$this->esc($headline)}'>
                    </div>
                </div>

                {$btnSave}
            </form>
        </div>";
    }
}

class Test4Headline extends Component
{
    public int $pageId = 0;
    public string $headline = '';
    public string $message = '';

    public function mount(): void
    {
        if ($this->pageId && !$this->headline) {
            $p = $this->wire('pages')->get($this->pageId);
            if ($p->id) {
                $this->headline = $p->get('headline|title');
            }
        }
    }

    protected function beforeRender(): void
    {
        $this->view = new HeadlineUiView();
    }

    public function save(): void
    {
        $newHeadline = $this->wire('input')->post('headline', 'text');

        $p = $this->wire('pages')->get($this->pageId);
        if ($p->id && $p->hasField('headline') && $newHeadline) {
            $p->of(false);
            $p->headline = $newHeadline;
            $p->save('headline');

            $this->headline = $newHeadline;
            $this->message = "Headline successfully updated via UI Component!";
        } else {
            $this->message = "Error: Invalid page or field missing.";
        }
    }
}
