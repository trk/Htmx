<?php

namespace Ui;

use Totoglu\Htmx\Ui;

class Button extends Ui
{
    public array $defaultParams = [
        'tag' => 'button',
        'type' => 'button',
        'label' => 'Click Me',
        'variant' => 'primary'
    ];

    public function render(): string
    {
        // Define variant classes
        $variants = [
            'primary' => 'bg-blue-600 text-white hover:bg-blue-700',
            'secondary' => 'bg-gray-600 text-white hover:bg-gray-700',
            'danger' => 'bg-red-600 text-white hover:bg-red-700',
            'success' => 'bg-green-600 text-white hover:bg-green-700',
        ];

        $variantClass = $variants[$this->param('variant')] ?? $variants['primary'];

        // Base classes
        $baseClass = "px-4 py-2 rounded shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 transition-colors duration-200 {$variantClass}";

        // Apply classes
        $this->addClass($baseClass);

        return sprintf(
            '<%s %s>%s</%s>',
            $this->param('tag'),
            $this->attributes->render(),
            $this->param('label'),
            $this->param('tag')
        );
    }
}
