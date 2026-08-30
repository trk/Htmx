<?php

declare(strict_types=1);

namespace Totoglu\Htmx;

use ProcessWire\WireData;

/**
 * Response
 * Helps build the outgoing HTMX response headers.
 */
class Response extends WireData
{
    private const DEFERRED_SWAP_EVENT = '__pwHtmxAfterSwap';
    private const DEFERRED_SETTLE_EVENT = '__pwHtmxAfterSettle';

    private array $triggers = [];
    private array $triggersAfterSettle = [];
    private array $triggersAfterSwap = [];
    private array $oobSwaps = [];

    /**
     * Strip CR/LF characters from header values to prevent header injection.
     */
    private function safeHeaderValue(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }

    /**
     * Send a client-side redirect.
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
     */
    public function pushUrl(string|bool $url): self
    {
        $val = is_bool($url) ? ($url ? 'true' : 'false') : $this->safeHeaderValue($url);
        header('HX-Push-Url: ' . $val);
        return $this;
    }

    /**
     * Replace the current URL in the browser's history stack.
     */
    public function replaceUrl(string|bool $url): self
    {
        $val = is_bool($url) ? ($url ? 'true' : 'false') : $this->safeHeaderValue($url);
        header('HX-Replace-Url: ' . $val);
        return $this;
    }

    /**
     * Set the target of the response.
     */
    public function retarget(string $selector): self
    {
        header('HX-Retarget: ' . $this->safeHeaderValue($selector));
        return $this;
    }

    /**
     * Modify the swap behavior of the response.
     */
    public function reswap(string $modifier): self
    {
        header('HX-Reswap: ' . $this->safeHeaderValue($modifier));
        return $this;
    }

    /**
     * Force the browser to evaluate the response as location.
     */
    public function location(string|array $url): self
    {
        $val = is_array($url) ? json_encode($url) : $this->safeHeaderValue($url);
        header('HX-Location: ' . $this->safeHeaderValue($val));
        return $this;
    }

    /**
     * Add an event trigger to be fired immediately.
     */
    public function trigger(string $name, mixed $data = null): self
    {
        $this->triggers[$name] = $data;
        $this->applyTriggers('HX-Trigger', $this->triggers);
        return $this;
    }

    /**
     * Add an event trigger to be fired after the settle phase.
     */
    public function triggerAfterSettle(string $name, mixed $data = null): self
    {
        $this->triggersAfterSettle[$name] = $data;
        $this->applyDeferredTriggers(self::DEFERRED_SETTLE_EVENT, $this->triggersAfterSettle);
        return $this;
    }

    /**
     * Add an event trigger to be fired after the swap phase.
     */
    public function triggerAfterSwap(string $name, mixed $data = null): self
    {
        $this->triggersAfterSwap[$name] = $data;
        $this->applyDeferredTriggers(self::DEFERRED_SWAP_EVENT, $this->triggersAfterSwap);
        return $this;
    }

    /**
     * Helper to correctly JSON-encode the triggers map.
     */
    private function applyTriggers(string $header, array $triggers): void
    {
        $payload = [];
        foreach ($triggers as $name => $data) {
            $payload[$name] = $data ?? '';
        }

        // JSON encode is inherently header-safe (no raw newlines), but double-check.
        $encoded = json_encode($payload);
        if ($encoded === false) {
            $encoded = '{}';
        }
        // Apply via PHP header with safety for header injection (JSON rarely contains CR/LF but defense-in-depth)
        header(sprintf('%s: %s', $this->safeHeaderValue($header), $this->safeHeaderValue($encoded)));
    }

    /**
     * HTMX v4 removed the dedicated deferred-trigger headers.
     * We tunnel deferred events through HX-Trigger and let the client runtime
     * re-dispatch them at the requested lifecycle phase.
     */
    private function applyDeferredTriggers(string $eventName, array $triggers): void
    {
        $this->triggers[$eventName] = ['events' => $triggers];
        $this->applyTriggers('HX-Trigger', $this->triggers);
    }

    /**
     * Return HTTP 286 to stop HTMX polling.
     */
    public function stopPolling(): void
    {
        http_response_code(286);
    }

    /**
     * Send a 422 validation error response that can be handled with hx-status.
     */
    public function validationError(?string $target = null): self
    {
        http_response_code(422);

        if ($target) {
            $this->retarget($target);
        }

        return $this;
    }
}
