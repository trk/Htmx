<?php

declare(strict_types=1);

namespace Totoglu\Htmx;

use ProcessWire\WireData;
use ProcessWire\WirePermissionException;

/**
 * Request
 * Represents the incoming HX-* headers parsing for HTMX v4.
 *
 * HTMX v4 header reference:
 *  - HX-Request: "true" for every htmx request
 *  - HX-Boosted: "true" when hx-boost is active
 *  - HX-History-Restore-Request: "true" for history traversal
 *  - HX-Current-URL: the URL of the browser address bar
 *  - HX-Target: target descriptor "tagName#id" (HTMX v4 format)
 *  - HX-Source: source trigger descriptor "tagName#id" (HTMX v4)
 *  - HX-Request-Type: "full" or "partial" (HTMX v4)
 *  - HX-Prompt: user response to hx-prompt (requires hx-prompt extension)
 *  - Accept: text/html
 */
class Request extends WireData
{
	/**
	 * Strict HTML id validation used when normalizing HTMX v4 header descriptors.
	 */
	private const HTML_ID_PATTERN = '/^[A-Za-z][A-Za-z0-9\-_:.]*$/';

	/**
	 * Determine if the request is an HTMX request.
	 */
	public function isHtmx(): bool
	{
		return $this->getHeader('HTTP_HX_REQUEST') === 'true';
	}

	/**
	 * Determine if the request is an HTMX boosted request.
	 */
	public function isBoosted(): bool
	{
		return $this->getHeader('HTTP_HX_BOOSTED') === 'true';
	}

	/**
	 * Determine if the request is a history restore request.
	 */
	public function isHistoryRestore(): bool
	{
		return $this->getHeader('HTTP_HX_HISTORY_RESTORE_REQUEST') === 'true';
	}

	/**
	 * Alias of isHistoryRestore() matching AGENTS.md / README.md documentation.
	 */
	public function historyRestore(): bool
	{
		return $this->isHistoryRestore();
	}

	/**
	 * Get the current URL from the HTMX request.
	 */
	public function currentUrl(): ?string
	{
		return $this->getHeader('HTTP_HX_CURRENT_URL');
	}

	/**
	 * HTMX v4: HX-Request-Type is "full" (boosted page load) or "partial" (fragment request).
	 */
	public function requestType(): ?string
	{
		return $this->getHeader('HTTP_HX_REQUEST_TYPE');
	}

	/**
	 * Whether the current HTMX request represents a full-page boosted load.
	 */
	public function isFullPage(): bool
	{
		return $this->requestType() === 'full';
	}

	/**
	 * Whether the current HTMX request represents a partial fragment swap.
	 */
	public function isPartial(): bool
	{
		return $this->requestType() === 'partial';
	}

	/**
	 * Get the ID of the target element.
	 */
	public function target(): ?string
	{
		return $this->extractElementId($this->rawTarget());
	}

	/**
	 * Get the tag name of the target element from the HTMX v4 descriptor (tagName#id form).
	 */
	public function targetTag(): ?string
	{
		return $this->extractTagName($this->rawTarget());
	}

	/**
	 * Get the raw HTMX target descriptor as sent by the browser.
	 *
	 * In HTMX v4 this is typically "tag#id" or just "tag".
	 */
	public function rawTarget(): ?string
	{
		return $this->getHeader('HTTP_HX_TARGET');
	}

	/**
	 * Get the ID of the triggering element from HX-Source.
	 */
	public function trigger(): ?string
	{
		return $this->extractElementId($this->rawSource());
	}

	/**
	 * Get the tag name of the triggering/source element from the HTMX v4 descriptor.
	 */
	public function sourceTag(): ?string
	{
		return $this->extractTagName($this->rawSource());
	}

	/**
	 * Get the raw HTMX source descriptor as sent by the browser.
	 *
	 * In HTMX v4 this is "tagName#id" via HX-Source.
	 */
	public function rawSource(): ?string
	{
		return $this->getHeader('HTTP_HX_SOURCE');
	}

	/**
	 * Alias of rawSource() for readable DX. Matches header name HX-Source (HTMX v4).
	 */
	public function source(): ?string
	{
		return $this->rawSource();
	}

	/**
	 * Extract the trigger identifier from HX-Source (id portion), falling back
	 * to the HTMX v4 `__htmx_action` parameter in POST or GET when present.
	 */
	public function triggerName(): ?string
	{
		$sourceId = $this->trigger();
		if (is_string($sourceId) && $sourceId !== '') {
			return $sourceId;
		}

		try {
			$input = $this->wire()->input;
			if ($input !== null) {
				$postAction = $input->post('__htmx_action');
				if (is_string($postAction) && $postAction !== '') {
					return $postAction;
				}
				$getAction = $input->get('__htmx_action');
				if (is_string($getAction) && $getAction !== '') {
					return $getAction;
				}
			}
		} catch (\Throwable) {
			// fall through
		}

		return null;
	}

	/**
	 * Get the user response to an hx-prompt.
	 *
	 * Note: in HTMX v4 this header is only present when the hx-prompt extension is loaded.
	 */
	public function prompt(): ?string
	{
		return $this->getHeader('HTTP_HX_PROMPT');
	}

	/**
	 * Return all inbound HX-* headers keyed without the `HX-` prefix (lowercase).
	 *
	 * @return array<string, string>
	 */
	public function headers(): array
	{
		$result = [];

		if (function_exists('getallheaders')) {
			$rawHeaders = @getallheaders();
			if (is_array($rawHeaders)) {
				foreach ($rawHeaders as $k => $v) {
					if (is_string($k) && stripos($k, 'HX-') === 0 && is_scalar($v)) {
						$shortKey = strtolower(substr($k, 3));
						$result[$shortKey] = (string) $v;
					}
				}
			}
		}

		foreach ($_SERVER as $k => $v) {
			if (is_string($k) && str_starts_with($k, 'HTTP_HX_') && is_scalar($v)) {
				$shortKey = strtolower(str_replace('_', '-', substr($k, 8)));
				if (!array_key_exists($shortKey, $result)) {
					$result[$shortKey] = (string) $v;
				}
			}
		}

		return $result;
	}

	/**
	 * Public accessor for reading a specific HTTP header from the inbound request.
	 *
	 * Accepts both canonical HTTP names ("HX-Source") and CGI-style names
	 * ("HTTP_HX_SOURCE").
	 */
	public function header(string $name): ?string
	{
		return $this->getHeader($name);
	}

	/**
	 * Validates ProcessWire's CSRF token for the current POST request.
	 * Can optionally throw an exception.
	 */
	public function validateCsrf(bool $throwException = false): bool
	{
		$session = $this->wire()->session;

		if ($session === null || !isset($session->CSRF) || !is_object($session->CSRF)) {
			if ($throwException) {
				throw new WirePermissionException("CSRF Validation Failed: Session CSRF manager unavailable.");
			}
			return false;
		}

		if (!method_exists($session->CSRF, 'hasValidToken')) {
			if ($throwException) {
				throw new WirePermissionException("CSRF Validation Failed: CSRF manager incompatible.");
			}
			return false;
		}

		$valid = (bool) $session->CSRF->hasValidToken();

		if (!$valid && $throwException) {
			throw new WirePermissionException("CSRF Validation Failed. Unauthorized HTMX request.");
		}

		return $valid;
	}

	/**
	 * Helper to read a specific server header safely.
	 */
	private function getHeader(string $name): ?string
	{
		$canonical = $name;
		if (str_starts_with($name, 'HTTP_')) {
			$canonical = substr($name, 5);
			$canonical = str_replace('_', '-', $canonical);
		}

		$input = $this->wire()->input;
		if ($input !== null && method_exists($input, 'getHeader')) {
			try {
				$value = $input->getHeader($canonical);
				if ($value !== null && $value !== '') {
					return is_string($value) ? $value : (string) $value;
				}
				$value = $input->getHeader($name);
				if ($value !== null && $value !== '') {
					return is_string($value) ? $value : (string) $value;
				}
			} catch (\Throwable) {
				// fall through to polyfills
			}
		}

		if (function_exists('getallheaders')) {
			$headers = @getallheaders();
			if (is_array($headers) && $headers !== []) {
				foreach ($headers as $k => $v) {
					if (is_string($k) && strcasecmp($k, $canonical) === 0) {
						return is_string($v) ? $v : (string) $v;
					}
					if (is_string($k) && strcasecmp($k, $name) === 0) {
						return is_string($v) ? $v : (string) $v;
					}
				}
			}
		}

		if (isset($_SERVER[$name]) && is_scalar($_SERVER[$name])) {
			return (string) $_SERVER[$name];
		}

		$httpKey = 'HTTP_' . strtoupper(str_replace('-', '_', $canonical));
		if ($httpKey !== $name && isset($_SERVER[$httpKey]) && is_scalar($_SERVER[$httpKey])) {
			return (string) $_SERVER[$httpKey];
		}

		return null;
	}

	/**
	 * Common HTML tag names that HTMX v4 `#buildIdentifier(elt)` emits when an element has no `id`.
	 */
	private const BARE_HTML_TAGS = [
		'a', 'article', 'aside', 'body', 'button', 'details', 'dialog', 'div', 'fieldset',
		'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'html',
		'img', 'input', 'label', 'li', 'main', 'nav', 'ol', 'option', 'output', 'p',
		'section', 'select', 'span', 'summary', 'table', 'tbody', 'td', 'textarea', 'tfoot',
		'th', 'thead', 'tr', 'ul',
	];

	/**
	 * Normalize HTMX v4 element descriptors down to an HTML id when possible.
	 *
	 * Accepts CSS-style ids ("#result-panel"), plain ids ("result-panel"),
	 * and HTMX v4 descriptors ("div#result-panel"). Returns null when the
	 * descriptor does not carry an id (for example plain "div" or "button").
	 */
	private function extractElementId(?string $value): ?string
	{
		if ($value === null) {
			return null;
		}

		$value = trim($value);
		if ($value === '') {
			return null;
		}

		$hashPos = strrpos($value, '#');
		if ($hashPos !== false) {
			$candidate = substr($value, $hashPos + 1);
			return preg_match(self::HTML_ID_PATTERN, $candidate) === 1 ? $candidate : null;
		}

		if (in_array(strtolower($value), self::BARE_HTML_TAGS, true)) {
			return null;
		}

		return preg_match(self::HTML_ID_PATTERN, $value) === 1 ? $value : null;
	}

	/**
	 * Extract the tag name prefix from an HTMX v4 element descriptor ("tagName#id" form).
	 *
	 * Examples:
	 *   "button#save"  => "button"
	 *   "div"          => "div"
	 *   "#target"      => null (no tag prefix)
	 */
	private function extractTagName(?string $value): ?string
	{
		if ($value === null) {
			return null;
		}

		$value = trim($value);
		if ($value === '' || $value[0] === '#') {
			return null;
		}

		$hashPos = strpos($value, '#');
		$tag = $hashPos !== false ? substr($value, 0, $hashPos) : $value;
		$tag = trim($tag);

		return $tag !== '' && preg_match('/^[A-Za-z][A-Za-z0-9\-]*$/', $tag) === 1 ? strtolower($tag) : null;
	}
}
