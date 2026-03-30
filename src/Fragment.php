<?php

namespace Totoglu\ProcessWire\Htmx;

use ProcessWire\WireData;
use ProcessWire\TemplateFile;

/**
 * Fragment
 * Manages rendering isolated components and OOB (Out-Of-Band) swaps.
 */
class Fragment extends WireData
{
    private array $oobSwaps = [];

    /**
     * Store HTML to be swapped out-of-band at the end of the request.
     */
    public function addOobSwap(string $selector, string $html, string $swapStyle = 'outerHTML'): self
    {
        $html = trim($html);
        $isId = strpos($selector, '#') === 0;
        $id = $isId ? substr($selector, 1) : $selector;

        // Try to inject natively if the HTMl root node contains the target ID.
        // This avoids destructive <div> wrappers breaking tables or semantic structures.
        $pattern = '/^(<[a-zA-Z0-9\-]+)([^>]*id=[\'"]' . preg_quote($id, '/') . '[\'"][^>]*)(>)/i';
        
        if ($isId && preg_match($pattern, $html)) {
            // Inject hx-swap-oob into the existing root node
            $wrapped = preg_replace(
                $pattern,
                '$1$2 hx-swap-oob="' . htmlspecialchars($swapStyle, ENT_QUOTES, 'UTF-8') . '"$3',
                $html
            );
        } else {
            // Fallback: wrap it in a div targeting the ID/selector
            $wrapped = sprintf(
                '<div id="%s" hx-swap-oob="%s">%s</div>',
                htmlentities($id, ENT_QUOTES, 'UTF-8'),
                htmlentities($swapStyle, ENT_QUOTES, 'UTF-8'),
                $html
            );
        }

        $this->oobSwaps[] = $wrapped;
        return $this;
    }

    /**
     * Get all currently queued OOB swaps as a single string.
     */
    public function getOobSwaps(): string
    {
        return implode("\n", $this->oobSwaps);
    }

    /**
     * Helper to render a specific PHP component file similar to ProcessWire's `$files->render()`.
     */
    public function renderFile(string $filename, array $options = []): string
    {
        return $this->wire('files')->render($filename, $options);
    }

    /**
     * Add a Hyperscript block to be executed via an OOB swap into the body.
     */
    public function addHyperscript(string $script): self
    {
        $this->oobSwaps[] = sprintf(
            '<script type="text/hyperscript" hx-swap-oob="beforeend:body">%s</script>',
            $script
        );

        return $this;
    }
}
