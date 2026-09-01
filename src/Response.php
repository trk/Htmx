<?php

declare(strict_types=1);

namespace Totoglu\Htmx;

use ProcessWire\WireData;

/**
 * Response
 * Helps build the outgoing HTMX v4 response headers.
 *
 * HTMX v4 response header reference:
 *  - HX-Location:       client-side redirect without full page reload
 *  - HX-Push-Url:       push URL to browser history
 *  - HX-Redirect:       full page redirect (navigator-level)
 *  - HX-Refresh:        "true" → full page refresh
 *  - HX-Replace-Url:    replace current history entry
 *  - HX-Reswap:         override hx-swap for this response
 *  - HX-Retarget:       override hx-target for this response
 *  - HX-Reselect:       override hx-select for this response (select sub-fragment from response HTML)
 *  - HX-Trigger:        fire events immediately (JSON map of event → detail)
 *
 * NOTE: HTMX v4 removed the dedicated HX-Trigger-After-Swap and
 * HX-Trigger-After-Settle headers. Deferred events are tunneled through
 * the single HX-Trigger header using internal synthetic events and are
 * re-dispatched by the companion runtime script (pw-htmx-runtime.js).
 */
class Response extends WireData
{
    private const DEFERRED_SWAP_EVENT = '__pwHtmxAfterSwap';
    private const DEFERRED_SETTLE_EVENT = '__pwHtmxAfterSettle';

    /** @var array<string, mixed> Events fired immediately via HX-Trigger. */
    private array $triggers = [];

    /** @var array<string, mixed> Events tunneled for the after-swap lifecycle. */
    private array $triggersAfterSwap = [];

    /** @var array<string, mixed> Events tunneled for the after-settle lifecycle. */
    private array $triggersAfterSettle = [];

    /**
     * Strip CR/LF characters from header values to prevent header injection.
     */
    private function safeHeaderValue(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }

    /**
     * Send a client-side redirect via HX-Redirect (navigates the whole page).
     */
    public function redirect(string $url): self
    {
        header('HX-Redirect: ' . $this->safeHeaderValue($url));
        return $this;
    }

    /**
     * Instruct HTMX to do a full page refresh.
     */
    public function refresh(): self
    {
        header('HX-Refresh: true');
        return $this;
    }

    /**
     * Push a new URL into the browser's history stack.
     *
     * Pass `true` to push the current URL, `false` to explicitly disable history.
     */
    public function pushUrl(string|bool $url): self
    {
        $val = is_bool($url) ? ($url ? 'true' : 'false') : $this->safeHeaderValue($url);
        header('HX-Push-Url: ' . $val);
        return $this;
    }

    /**
     * Replace the current URL in the browser's history stack (no new entry).
     */
    public function replaceUrl(string|bool $url): self
    {
        $val = is_bool($url) ? ($url ? 'true' : 'false') : $this->safeHeaderValue($url);
        header('HX-Replace-Url: ' . $val);
        return $this;
    }

    /**
     * HX-Location: navigate to a URL using HTMX's own location logic
     * (behaves like a boosted link, supports a detail object for options).
     */
    public function location(string|array $url): self
    {
        $val = is_array($url) ? json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $this->safeHeaderValue($url);
        header('HX-Location: ' . $this->safeHeaderValue($val));
        return $this;
    }

    /**
     * Override the target of the response (swap into a different element).
     */
    public function retarget(string $selector): self
    {
        header('HX-Retarget: ' . $this->safeHeaderValue($selector));
        return $this;
    }

    /**
     * Override the swap behavior of the response.
     */
    public function reswap(string $modifier): self
    {
        header('HX-Reswap: ' . $this->safeHeaderValue($modifier));
        return $this;
    }

    /**
     * HTMX v4: Override which sub-fragment of the response HTML is selected
     * for swapping. Equivalent to the hx-select attribute, but applied per-response.
     *
     * Example: reselect('#error-summary') to only swap the error block.
     */
    public function reselect(string $selector): self
    {
        header('HX-Reselect: ' . $this->safeHeaderValue($selector));
        return $this;
    }

    /**
     * Add an event trigger to be fired IMMEDIATELY when the response headers arrive
     * (before any swap happens). Use triggerAfterSwap / triggerAfterSettle for UI events.
     */
    public function trigger(string $name, mixed $data = null): self
    {
        $this->triggers[$name] = $data;
        $this->applyTriggers();
        return $this;
    }

    /**
     * Add an event trigger to be fired AFTER the swap phase completes.
     *
     * This is the ideal moment for most UI events: the new DOM is in place.
     */
    public function triggerAfterSwap(string $name, mixed $data = null): self
    {
        $this->triggersAfterSwap[$name] = $data;
        $this->applyTriggers();
        return $this;
    }

    /**
     * Add an event trigger to be fired AFTER the settle phase completes
     * (all CSS transitions / scroll animations finished).
     */
    public function triggerAfterSettle(string $name, mixed $data = null): self
    {
        $this->triggersAfterSettle[$name] = $data;
        $this->applyTriggers();
        return $this;
    }

    /**
     * Helper to correctly JSON-encode the triggers map and emit the HX-Trigger header.
     *
     * Merges immediate events with tunneled deferred-event envelopes.
     */
    private function applyTriggers(): void
    {
        $payload = [];

        foreach ($this->triggers as $name => $data) {
            $payload[$name] = $data ?? '';
        }

        if ($this->triggersAfterSwap !== []) {
            $payload[self::DEFERRED_SWAP_EVENT] = ['events' => $this->triggersAfterSwap];
        }

        if ($this->triggersAfterSettle !== []) {
            $payload[self::DEFERRED_SETTLE_EVENT] = ['events' => $this->triggersAfterSettle];
        }

        if ($payload === []) {
            return;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $encoded = '{}';
        }
        header('HX-Trigger: ' . $this->safeHeaderValue($encoded));
    }

    /**
     * Return HTTP 286 to stop HTMX polling (every 2s polling → terminate).
     */
    public function stopPolling(): void
    {
        http_response_code(286);
    }

    /**
     * Send a 4xx validation error response. Defaults to HTTP 422 Unprocessable Entity.
     *
     * HTMX v4 swaps error responses (4xx/5xx) by default, so you can return
     * a validation-error form fragment directly. Pair with:
     *   hx-status:422="swap:innerHTML target:#errors"
     */
    public function validationError(?string $target = null, ?string $select = null, int $httpCode = 422): self
    {
        http_response_code($httpCode);

        if ($target !== null) {
            $this->retarget($target);
        }

        if ($select !== null) {
            $this->reselect($select);
        }

        return $this;
    }

    /**
     * Send a 200 OK with explicit no-op swap semantics via HX-Reswap.
     * Useful when the response carries only OOB swaps or events and you
     * do not want the main target to be modified.
     */
    public function noSwap(): self
    {
        return $this->reswap('none');
    }

    /**
     * Emit all deferred headers accumulated during the request lifecycle.
     *
     * Safe no-op when headers have already been sent. Always re-applies
     * HX-Trigger so deferred afterSwap / afterSettle events are included
     * even if they were added after the initial trigger() call.
     */
    public function applyHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        // Re-emit the merged HX-Trigger header so any late additions
        // (flash messages, OOB notifications, etc.) are included.
        $this->applyTriggers();
    }
}
