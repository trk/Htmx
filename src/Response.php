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
 *  - HX-Trigger:        fire events (JSON map of event → detail)
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

	/** @var array<string, string> Outgoing HX-* headers buffered on this response. */
	private array $headers = [];

	/** @var int|null Explicit HTTP status code set on this response. */
	private ?int $statusCode = null;

	/**
	 * Strip CR/LF/NUL characters from header values to prevent header injection.
	 */
	private function safeHeaderValue(string $value): string
	{
		return str_replace(["\r", "\n", "\0"], '', $value);
	}

	/**
	 * Store a header in-memory and emit it immediately when headers have not been sent.
	 */
	private function emitHeader(string $name, string $value): void
	{
		$clean = $this->safeHeaderValue($value);
		$this->headers[$name] = $clean;
		if (!headers_sent()) {
			header("{$name}: {$clean}");
		}
	}

	/**
	 * Store and emit an HTTP response status code.
	 */
	private function emitStatusCode(int $code): void
	{
		$this->statusCode = $code;
		if (!headers_sent()) {
			http_response_code($code);
		}
	}

	/**
	 * Inspect all buffered outgoing HX-* headers (test surface).
	 *
	 * @return array<string, string>
	 */
	public function getHeaders(): array
	{
		$this->syncTriggerHeader();
		return $this->headers;
	}

	/**
	 * Inspect a single buffered outgoing header by name.
	 */
	public function getHeader(string $name): ?string
	{
		$this->syncTriggerHeader();
		foreach ($this->headers as $k => $v) {
			if (strcasecmp($k, $name) === 0) {
				return $v;
			}
		}
		return null;
	}

	/**
	 * Inspect the explicit HTTP status code set on this response, if any.
	 */
	public function getStatusCode(): ?int
	{
		return $this->statusCode;
	}

	/**
	 * Reset buffered headers, triggers, and status code.
	 */
	public function reset(): self
	{
		$this->triggers = [];
		$this->triggersAfterSwap = [];
		$this->triggersAfterSettle = [];
		$this->headers = [];
		$this->statusCode = null;
		return $this;
	}

	/**
	 * Send a client-side redirect via HX-Redirect (navigates the whole page).
	 */
	public function redirect(string $url): self
	{
		$this->emitHeader('HX-Redirect', $url);
		return $this;
	}

	/**
	 * Instruct HTMX to do a full page refresh.
	 */
	public function refresh(): self
	{
		$this->emitHeader('HX-Refresh', 'true');
		return $this;
	}

	/**
	 * Push a new URL into the browser's history stack.
	 *
	 * Pass `true` to push the current URL, `false` to explicitly disable history.
	 */
	public function pushUrl(string|bool $url = true): self
	{
		$val = is_bool($url) ? ($url ? 'true' : 'false') : $url;
		$this->emitHeader('HX-Push-Url', $val);
		return $this;
	}

	/**
	 * Replace the current URL in the browser's history stack (no new entry).
	 */
	public function replaceUrl(string|bool $url = true): self
	{
		$val = is_bool($url) ? ($url ? 'true' : 'false') : $url;
		$this->emitHeader('HX-Replace-Url', $val);
		return $this;
	}

	/**
	 * HX-Location: navigate to a URL using HTMX's own location logic
	 * (behaves like a boosted link, supports an options array as second argument or full array).
	 *
	 * @param string|array<string, mixed> $url
	 * @param array<string, mixed> $options
	 */
	public function location(string|array $url, array $options = []): self
	{
		if (is_array($url)) {
			$encoded = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			$val = is_string($encoded) ? $encoded : '{}';
		} elseif ($options !== []) {
			$payload = array_merge(['path' => $url], $options);
			$encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			$val = is_string($encoded) ? $encoded : $url;
		} else {
			$val = $url;
		}

		$this->emitHeader('HX-Location', $val);
		return $this;
	}

	/**
	 * Override the target of the response (swap into a different element).
	 */
	public function retarget(string $selector): self
	{
		$this->emitHeader('HX-Retarget', $selector);
		return $this;
	}

	/**
	 * Override the swap behavior of the response.
	 */
	public function reswap(string $modifier): self
	{
		$this->emitHeader('HX-Reswap', $modifier);
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
		$this->emitHeader('HX-Reselect', $selector);
		return $this;
	}

	/**
	 * Add an event trigger to be fired via HX-Trigger.
	 *
	 * Supports `:swap` and `:settle` suffixes on the event name to route
	 * directly into afterSwap or afterSettle deferred queues.
	 */
	public function trigger(string $name, mixed $data = null): self
	{
		if (str_ends_with($name, ':swap')) {
			$cleanName = substr($name, 0, -5);
			if ($cleanName !== '') {
				return $this->triggerAfterSwap($cleanName, $data);
			}
		}

		if (str_ends_with($name, ':settle')) {
			$cleanName = substr($name, 0, -7);
			if ($cleanName !== '') {
				return $this->triggerAfterSettle($cleanName, $data);
			}
		}

		$this->triggers[$name] = $data;
		$this->applyTriggers();
		return $this;
	}

	/**
	 * Add an event trigger to be fired AFTER the swap phase completes.
	 */
	public function triggerAfterSwap(string $name, mixed $data = null): self
	{
		$this->triggersAfterSwap[$name] = $data;
		$this->applyTriggers();
		return $this;
	}

	/**
	 * Add an event trigger to be fired AFTER the settle phase completes.
	 */
	public function triggerAfterSettle(string $name, mixed $data = null): self
	{
		$this->triggersAfterSettle[$name] = $data;
		$this->applyTriggers();
		return $this;
	}

	/**
	 * Bulk-set triggers from an associative array.
	 *
	 * Keys ending with `:swap` are routed to afterSwap; keys ending with `:settle`
	 * are routed to afterSettle; all other keys are dispatched immediately.
	 *
	 * @param array<string, mixed> $triggers
	 */
	public function setTriggers(array $triggers): self
	{
		foreach ($triggers as $name => $data) {
			if (!is_string($name) || $name === '') {
				continue;
			}
			if (str_ends_with($name, ':swap')) {
				$cleanName = substr($name, 0, -5);
				if ($cleanName !== '') {
					$this->triggersAfterSwap[$cleanName] = $data;
				}
			} elseif (str_ends_with($name, ':settle')) {
				$cleanName = substr($name, 0, -7);
				if ($cleanName !== '') {
					$this->triggersAfterSettle[$cleanName] = $data;
				}
			} else {
				$this->triggers[$name] = $data;
			}
		}

		$this->applyTriggers();
		return $this;
	}

	/**
	 * Build the merged HX-Trigger payload array.
	 *
	 * @return array<string, mixed>
	 */
	private function buildTriggerPayload(): array
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

		return $payload;
	}

	/**
	 * Synchronize the in-memory `HX-Trigger` header from current trigger queues.
	 */
	private function syncTriggerHeader(): void
	{
		$payload = $this->buildTriggerPayload();
		if ($payload === []) {
			unset($this->headers['HX-Trigger']);
			return;
		}

		$encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($encoded === false) {
			$encoded = '{}';
		}

		$this->headers['HX-Trigger'] = $this->safeHeaderValue($encoded);
	}

	/**
	 * Helper to correctly JSON-encode the triggers map and emit the HX-Trigger header.
	 */
	private function applyTriggers(): void
	{
		$this->syncTriggerHeader();
		if (isset($this->headers['HX-Trigger']) && !headers_sent()) {
			header('HX-Trigger: ' . $this->headers['HX-Trigger']);
		}
	}

	/**
	 * Return HTTP 286 to stop HTMX polling (every 2s polling → terminate).
	 */
	public function stopPolling(): void
	{
		$this->emitStatusCode(286);
	}

	/**
	 * Send a 4xx validation error response. Defaults to HTTP 422 Unprocessable Entity.
	 *
	 * HTMX v4 swaps error responses (4xx/5xx) by default, so you can return
	 * a validation-error form fragment directly. Pair with:
	 *   hx-status:422="swap:outerMorph target:#errors"
	 */
	public function validationError(?string $target = null, ?string $select = null, int $httpCode = 422): self
	{
		$this->emitStatusCode($httpCode);

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
	 */
	public function noSwap(): self
	{
		return $this->reswap('none');
	}

	/**
	 * Emit all deferred headers accumulated during the request lifecycle.
	 */
	public function applyHeaders(): void
	{
		$this->syncTriggerHeader();

		if (headers_sent()) {
			return;
		}

		if ($this->statusCode !== null) {
			http_response_code($this->statusCode);
		}

		foreach ($this->headers as $name => $value) {
			header("{$name}: {$value}");
		}
	}
}
