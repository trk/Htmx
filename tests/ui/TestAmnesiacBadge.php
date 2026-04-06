<?php

namespace Htmx\Ui;

use Totoglu\Htmx\Ui;

class TestAmnesiacBadge extends Ui
{
    public array $defaultParams = [
        'label' => 'Unknown',
        'type' => 'info'
    ];

    public function render(): string
    {
        // Add core Tailwind utility classes
        $this->addClass('px-3 py-1 text-xs font-semibold rounded-full border');
        
        $type = $this->param('type');
        if ($type === 'danger') {
            $this->addClass('bg-red-100 text-red-800 border-red-200');
        } elseif ($type === 'success') {
            $this->addClass('bg-green-100 text-green-800 border-green-200');
        } else {
            $this->addClass('bg-blue-100 text-blue-800 border-blue-200');
        }
        
        // Output label localized via hyperscript-compatible _() function or direct param translation
        $label = $this->param('label');
        if ($label === 'Unknown') {
            $label = $this->_('Unknown Attribute'); // Wrapping default fallback text
        }
        
        // Render combined attribute bag safely
        return "<span {$this->attributes->render()}>{$label}</span>";
    }
}
