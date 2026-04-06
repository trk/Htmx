<?php

declare(strict_types=1);

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
        $this->attributes->addClass('px-3 py-1 text-xs font-semibold rounded-full border');
        
        $type = $this->parameters->getString('type');
        if ($type === 'danger') {
            $this->attributes->addClass('bg-red-100 text-red-800 border-red-200');
        } elseif ($type === 'success') {
            $this->attributes->addClass('bg-green-100 text-green-800 border-green-200');
        } else {
            $this->attributes->addClass('bg-blue-100 text-blue-800 border-blue-200');
        }
        
        // Output label localized via hyperscript-compatible _() function or direct param translation
        $label = $this->parameters->getString('label');
        if ($label === 'Unknown') {
            $label = $this->_('Unknown Attribute'); // Wrapping default fallback text
        }
        
        // Render combined attribute bag safely
        return "<span {$this->attributes->render()}>{$label}</span>";
    }
}
