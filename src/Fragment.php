<?php

declare(strict_types=1);

namespace Totoglu\Htmx;

use ProcessWire\WireData;

/**
 * Fragment
 * Manages rendering isolated components and OOB (Out-Of-Band) swaps.
 *
 * HTMX v4 adds:
 *  - New swap styles: innerMorph, outerMorph, textContent, delete (plus aliases before/after/prepend/append)
 *  - <hx-partial> elements as an alternative to hx-swap-oob for explicit multi-target responses
 */
class Fragment extends WireData
{
    /** @var array<int, string> Raw OOB swap fragments queued for this request. */
    private array $oobSwaps = [];

    /** @var array<int, array{target: string, html: string, swap: string, select: string|null}> Queued <hx-partial> elements. */
    private array $partials = [];

    /**
     * Whitelist of HTMX-valid hx-swap-oob / hx-swap values accepted by strict mode.
     *
     * HTMX v4 vocabulary:
     *  - Core:       innerHTML, outerHTML, beforebegin, afterbegin, beforeend, afterend, delete, none
     *  - Aliases:    before (=beforebegin), after (=afterend), prepend (=afterbegin), append (=beforeend)
     *  - Morph:      innerMorph, outerMorph, morphdom, morph
     *  - Text-only:  textContent
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
        'morph',
        // HTMX v4 aliases
        'before',
        'after',
        'prepend',
        'append',
        // HTMX v4 native morph + text swap styles
        'innermorph',
        'outermorph',
        'textcontent',
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
     * Validate a swap style against the HTMX v4 spec whitelist (with optional modifiers).
     *
     * Allowed shape: <base-swap> [modifier1 modifier2 ...]
     *   e.g. innerHTML show:#target swap:1s settle:500ms
     * Also allows the hx-swap-oob selector form: "afterend:#some-id"
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
     *
     * By default the HTML is wrapped (if needed) so hx-swap-oob="outerHTML"
     * targets the given selector. Pass a selector starting with "#" for best
     * strict-mode compatibility.
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
                $swapStyle = 'outerHTML';
            }
        }

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

        $pattern = '/^(<[a-zA-Z0-9\-]+)([^>]*id=[\'"]' . preg_quote($id, '/') . '[\'"][^>]*)(>)/i';

        if (preg_match($pattern, $html)) {
            $wrapped = preg_replace(
                $pattern,
                '$1$2 hx-swap-oob="' . htmlspecialchars($swapStyle, ENT_QUOTES, 'UTF-8') . '"$3',
                $html
            );
        } else {
            $wrapped = sprintf(
                '<div id="%s" hx-swap-oob="%s">%s</div>',
                htmlentities($id, ENT_QUOTES, 'UTF-8'),
                htmlentities($swapStyle, ENT_QUOTES, 'UTF-8'),
                $html
            );
        }

        if (is_string($wrapped) && $wrapped !== '') {
            $this->oobSwaps[] = $wrapped;
        }

        return $this;
    }

    /**
     * HTMX v4 alternative to OOB swaps. Queues an <hx-partial> element with
     * its own explicit target, swap style, and optional sub-selector.
     *
     * Use this when you want response-local control of multi-target updates
     * without depending on id attributes matching inside the returned HTML.
     *
     * @link https://four.htmx.org/docs#partials-hx-partial
     */
    public function addPartial(
        string $target,
        string $html,
        string $swapStyle = 'innerHTML',
        ?string $select = null
    ): self {
        $target = trim($target);
        $html = trim($html);
        if ($target === '' || $html === '') {
            return $this;
        }

        $strictSwap = false;
        $htmx = $this->wire('htmx');
        if ($htmx && isset($htmx->oobStrictSwapStyle)) {
            $strictSwap = (bool) $htmx->oobStrictSwapStyle;
        }

        if ($strictSwap && !$this->isValidSwapStyle($swapStyle)) {
            $config = $this->wire('config');
            if ($config && $config->debug) {
                $log = $this->wire('log');
                if ($log) {
                    $log->warning('HTMX Partial strict-swap rejected invalid swapStyle: ' . $swapStyle);
                }
            }
            $swapStyle = 'innerHTML';
        }

        $this->partials[] = [
            'target' => $target,
            'html'   => $html,
            'swap'   => $swapStyle,
            'select' => $select,
        ];

        return $this;
    }

    /**
     * Get all currently queued OOB swaps + <hx-partial> elements as a single string
     * ready to append to the response body.
     */
    public function getOobSwaps(): string
    {
        $pieces = $this->oobSwaps;

        // Render queued <hx-partial> elements after OOB entries (document order).
        foreach ($this->partials as $p) {
            $targetAttr = 'hx-target="' . htmlspecialchars($p['target'], ENT_QUOTES, 'UTF-8') . '"';
            $swapAttr   = 'hx-swap="' . htmlspecialchars($p['swap'], ENT_QUOTES, 'UTF-8') . '"';
            $selectAttr = $p['select'] !== null
                ? ' hx-select="' . htmlspecialchars($p['select'], ENT_QUOTES, 'UTF-8') . '"'
                : '';

            $pieces[] = "<hx-partial {$targetAttr} {$swapAttr}{$selectAttr}>\n" . $p['html'] . "\n</hx-partial>";
        }

        return implode("\n", $pieces);
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
