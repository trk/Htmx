<?php

declare(strict_types=1);

namespace Totoglu\Htmx;

use ProcessWire\WireData;

/**
 * Fragment
 * Manages rendering isolated components and OOB (Out-Of-Band) swaps.
 */
class Fragment extends WireData
{
    private array $oobSwaps = [];

    /**
     * Whitelist of HTMX-valid hx-swap-oob / hx-swap values accepted by strict mode.
     * These match the current HTMX v4 swap vocabulary used by the module.
     *
     * Append modifiers (e.g. innerHTML show:#foo) are validated separately after the base.
     */
    private const VALID_SWAP_BASE = [
        'innerHTML',
        'outerHTML',
        'beforebegin',
        'afterbegin',
        'beforeend',
        'afterend',
        'delete',
        'none',
        'morphdom',
        'morph' // Morph-style extensions / plugins
    ];

    private function isValidHtmlId(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z][A-Za-z0-9\-_:.]*$/', $id);
    }

    private function normalizeHtmlId(string $id): string
    {
        $replaced = preg_replace('/[^A-Za-z0-9\-_:.]/', '-', $id);
        $id = is_string($replaced) ? $replaced : $id;
        $id = trim($id, '-');
        if ($id === '' || !$this->isValidHtmlId($id)) {
            $id = 'hx-' . substr(hash('sha1', $id . microtime(true)), 0, 12);
        }
        return $id;
    }

    /**
     * Validate a swap style against the HTMX spec whitelist (with optional modifiers).
     *
     * Allowed shape: <base-swap> [modifier1 modifier2 ...]
     *   e.g. innerHTML show:#target swap:1s settle:500ms
     */
    private function isValidSwapStyle(string $swapStyle): bool
    {
        $swapStyle = trim($swapStyle);
        if ($swapStyle === '') {
            return false;
        }

        // Strip optional leading selector used in hx-swap-oob syntax:
        //   hx-swap-oob="true" (legacy), "outerHTML", or "afterend:#some-id"
        if (strpos($swapStyle, ':') !== false) {
            $parts = explode(':', $swapStyle, 2);
            $base = $parts[0] === '' ? 'outerHTML' : $parts[0];
            // Everything after the colon is selector / modifiers — skip deep check, just
            // ensure no raw CR/LF that would break attribute escaping
            $rest = $parts[1] ?? '';
            if (strcspn($rest, "\r\n\0") !== strlen($rest)) {
                return false;
            }
        } else {
            $tokens = preg_split('/\s+/', $swapStyle);
            $base = $tokens[0] ?? '';
        }

        $base = strtolower(trim((string) $base));
        return in_array($base, self::VALID_SWAP_BASE, true) || $base === 'true';
    }

    /**
     * Store HTML to be swapped out-of-band at the end of the request.
     */
    public function addOobSwap(string $selector, string $html, string $swapStyle = 'outerHTML'): self
    {
        $html = trim($html);
        $selector = trim($selector);
        $startsWithHash = strpos($selector, '#') === 0;
        $rawId = $startsWithHash ? substr($selector, 1) : $selector;

        $strictId = false;
        $strictSwap = false;
        $htmx = $this->wire('htmx');
        if ($htmx) {
            if (isset($htmx->oobStrictIdOnly)) {
                $strictId = (bool) $htmx->oobStrictIdOnly;
            }
            if (isset($htmx->oobStrictSwapStyle)) {
                $strictSwap = (bool) $htmx->oobStrictSwapStyle;
            }
        }

        // 1. Swap-style validation (strict mode on by default, can be opted out)
        if ($strictSwap || !$htmx) {
            if (!$this->isValidSwapStyle($swapStyle)) {
                $config = $this->wire('config');
                if ($config && $config->debug) {
                    $log = $this->wire('log');
                    if ($log) {
                        $log->warning(
                            'HTMX OOB strict-swap rejected invalid swapStyle: ' . $swapStyle
                        );
                    }
                }
                // Safe default fallback — never use user-supplied style
                $swapStyle = 'outerHTML';
            }
        }

        // 2. ID / selector strictness validation
        if ($strictId) {
            $isStrictIdSelector = $startsWithHash
                ? (bool) preg_match('/^#[A-Za-z][A-Za-z0-9\-_:.]*$/', $selector)
                : $this->isValidHtmlId($rawId);
            if (!$isStrictIdSelector) {
                $config = $this->wire('config');
                if ($config && $config->debug) {
                    $log = $this->wire('log');
                    if ($log) {
                        $log->warning('HTMX OOB strict mode rejected selector: ' . $selector);
                    }
                }
                return $this;
            }
        }

        $id = $this->normalizeHtmlId($rawId);

        // Try to inject natively if the HTMl root node contains the target ID.
        // This avoids destructive <div> wrappers breaking tables or semantic structures.
        $pattern = '/^(<[a-zA-Z0-9\-]+)([^>]*id=[\'"]' . preg_quote($id, '/') . '[\'"][^>]*)(>)/i';

        if (preg_match($pattern, $html)) {
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
