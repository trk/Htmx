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
		$safeName = preg_replace('/[^!#$%&\'*+.^_`|~0-9A-Za-z-]/', '', $name) ?? '';
		if ($safeName === '') {
			return;
		}
		$clean = $this->safeHeaderValue($value);
		$this->headers[$safeName] = $clean;
		if (!headers_sent()) {
			header("{$safeName}: {$clean}");
		}
	}

	/**
	 * Set a custom outgoing HTTP response header with RFC 9110 token & CRLF sanitization.
	 */
	public function setHeader(string $name, string $value): self
	{
		$this->emitHeader($name, $value);
		return $this;
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

	/** @var list<string> Allowed option keys in an `HX-Location` specification */
	private const ALLOWED_LOCATION_OPTIONS = [
		'source',
		'event',
		'handler',
		'target',
		'swap',
		'values',
		'headers',
		'select',
		'push',
		'replace',
		'confirm',
	];

	/**
	 * Sanitize a navigation URL to block dangerous pseudo-schemes (`js:`, `javascript:`, `data:`, `vbscript:`)
	 * and control characters before emitting HTMX navigation headers.
	 */
	public function sanitizeNavigationUrl(string $url): string
	{
		$clean = trim($this->safeHeaderValue($url));
		if ($clean === '' || str_contains($clean, '\\') || str_starts_with($clean, '//')) {
			return '/';
		}

		$normalized = preg_replace('/[\x00-\x20\x7F]+/', '', $clean) ?? '';
		if (preg_match('/^(?:js|javascript|data|vbscript|blob|file):/i', $normalized) === 1) {
			return '/';
		}

		return $clean;
	}

	/**
	 * Sanitize a URL passed to `HX-Push-Url` or `HX-Replace-Url`, enforcing same-origin
	 * so `history.pushState` / `history.replaceState` cannot throw `SecurityError` on cross-origin URLs.
	 */
	private function sanitizeHistoryUrl(string $url): string
	{
		$clean = $this->sanitizeNavigationUrl($url);
		if ($clean === '/' || str_starts_with($clean, '//')) {
			return '/';
		}

		if (preg_match('#^https?://#i', $clean) !== 1) {
			return str_contains((string) strtok($clean, '/?#'), ':') ? '/' : $clean;
		}

		$parts = parse_url($clean);
		if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
			return '/';
		}

		$host = strtolower(trim((string) ($parts['host'] ?? '')));
		if ($host === '') {
			return '/';
		}

		if (str_contains($host, ':') && !str_starts_with($host, '[')) {
			$host = '[' . $host . ']';
		}
		$port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
		$hostWithPort = $host . $port;

		$config = $this->wire()?->config;
		if ($config === null) {
			return '/';
		}

		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		if ((bool) $config->https && $scheme !== 'https') {
			return '/';
		}

		$allowedHosts = [];
		$candidates = [$config->httpHost];
		if (is_iterable($config->httpHosts)) {
			foreach ($config->httpHosts as $configuredHost) {
				$candidates[] = $configuredHost;
			}
		}

		foreach ($candidates as $candidate) {
			$normalizedHost = strtolower(trim((string) $candidate));
			if ($normalizedHost !== '') {
				$allowedHosts[] = $normalizedHost;
			}
		}

		if ($allowedHosts === []) {
			return '/';
		}

		return (in_array($hostWithPort, $allowedHosts, true) || ($port === '' && in_array($host, $allowedHosts, true)))
			? $clean
			: '/';
	}

	/**
	 * Sanitize an `HX-Location` options map against HCON / `js:` / unrecognised keys.
	 *
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private function sanitizeLocationOptions(array $options): array
	{
		$safe = [];
		foreach (self::ALLOWED_LOCATION_OPTIONS as $allowedKey) {
			if (!array_key_exists($allowedKey, $options)) {
				continue;
			}
			$val = $options[$allowedKey];
			if ($allowedKey === 'confirm' && is_string($val)) {
				while (preg_match('/^[\x00-\x20\x7F]*(?:js|javascript):/i', $val) === 1) {
					$val = (string) preg_replace('/^[\x00-\x20\x7F]*(?:js|javascript):/i', '', $val);
				}
			} elseif (($allowedKey === 'push' || $allowedKey === 'replace') && is_string($val)) {
				$val = $this->sanitizeHistoryUrl($val);
			}
			$safe[$allowedKey] = $val;
		}

		return $safe;
	}

	/**
	 * Send a client-side redirect via HX-Redirect (navigates the whole page).
	 * Enforces same-origin host validation by default unless `$allowExternal === true`.
	 */
	public function redirect(string $url, bool $allowExternal = false): self
	{
		$safeUrl = $allowExternal ? $this->sanitizeNavigationUrl($url) : $this->sanitizeHistoryUrl($url);
		$this->emitHeader('HX-Redirect', $safeUrl);
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
		$val = is_bool($url) ? ($url ? 'true' : 'false') : $this->sanitizeHistoryUrl($url);
		$this->emitHeader('HX-Push-Url', $val);
		return $this;
	}

	/**
	 * Replace the current URL in the browser's history stack (no new entry).
	 */
	public function replaceUrl(string|bool $url = true): self
	{
		$val = is_bool($url) ? ($url ? 'true' : 'false') : $this->sanitizeHistoryUrl($url);
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
			$safePath = isset($url['path']) && is_string($url['path'])
				? $this->sanitizeHistoryUrl($url['path'])
				: '/';
			if (str_starts_with($safePath, '{') || (preg_match('#^https?://#i', $safePath) !== 1 && str_contains((string) strtok($safePath, '/?#'), ':'))) {
				$safePath = '/';
			}
			$safeOpts = $this->sanitizeLocationOptions($url);
			$payload = array_merge(['path' => $safePath], $safeOpts);
			$encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
			$val = is_string($encoded) ? $encoded : '{"path":"/"}';
		} elseif ($options !== []) {
			$safePath = $this->sanitizeHistoryUrl($url);
			if (str_starts_with($safePath, '{') || (preg_match('#^https?://#i', $safePath) !== 1 && str_contains((string) strtok($safePath, '/?#'), ':'))) {
				$safePath = '/';
			}
			$safeOpts = $this->sanitizeLocationOptions($options);
			$payload = array_merge(['path' => $safePath], $safeOpts);
			$encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
			$val = is_string($encoded) ? $encoded : $safePath;
		} else {
			$safePath = $this->sanitizeHistoryUrl($url);
			if (str_starts_with($safePath, '{') || (preg_match('#^https?://#i', $safePath) !== 1 && str_contains((string) strtok($safePath, '/?#'), ':'))) {
				$safePath = '/';
			}
			if (preg_match('/[\s,{}"\']/', $safePath) === 1) {
				$encoded = json_encode(['path' => $safePath], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
				$val = is_string($encoded) ? $encoded : '/';
			} else {
				$val = $safePath;
			}
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
		$this->queueTrigger($name, $data);
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
			$this->queueTrigger($name, $data);
		}

		$this->applyTriggers();
		return $this;
	}

	/**
	 * Route a single trigger name into immediate, afterSwap (`:swap`), or afterSettle (`:settle`) queue.
	 */
	private function queueTrigger(string $name, mixed $data = null): void
	{
		if (str_ends_with($name, ':swap')) {
			$cleanName = substr($name, 0, -5);
			if ($cleanName !== '') {
				$this->triggersAfterSwap[$cleanName] = $data;
				return;
			}
		}

		if (str_ends_with($name, ':settle')) {
			$cleanName = substr($name, 0, -7);
			if ($cleanName !== '') {
				$this->triggersAfterSettle[$cleanName] = $data;
				return;
			}
		}

		$this->triggers[$name] = $data;
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

		$encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
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
	 * Append one or more header names to the outgoing `Vary` HTTP response header
	 * so intermediary/browser caches distinguish HTMX partial and boosted responses from full HTML pages.
	 */
	public function vary(string $header = 'HX-Request, HX-Boosted'): self
	{
		$existing = $this->getHeader('Vary') ?? '';
		if (trim($existing) === '*') {
			return $this;
		}

		$sources = [];
		if ($existing !== '') {
			$sources[] = $existing;
		}
		if (PHP_SAPI !== 'cli' && function_exists('headers_list')) {
			foreach (headers_list() as $sentHeader) {
				if (stripos($sentHeader, 'Vary:') === 0) {
					$sources[] = substr($sentHeader, 5);
				}
			}
		}
		$sources[] = $header;

		$tokens = [];
		foreach ($sources as $source) {
			foreach (explode(',', $source) as $part) {
				$trimmed = trim($this->safeHeaderValue($part));
				$cleanToken = (string) preg_replace('/[^!#$%&\'*+.^_`|~0-9A-Za-z-]/', '', $trimmed);
				if ($cleanToken === '*') {
					$this->emitHeader('Vary', '*');

					return $this;
				}
				if ($cleanToken === '') {
					continue;
				}
				$lower = strtolower($cleanToken);
				if (!isset($tokens[$lower])) {
					$tokens[$lower] = $cleanToken;
				}
			}
		}

		if ($tokens !== []) {
			$this->emitHeader('Vary', implode(', ', array_values($tokens)));
		}

		return $this;
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
