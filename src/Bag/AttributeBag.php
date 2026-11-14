<?php

declare(strict_types=1);

namespace Totoglu\Htmx\Bag;

use ProcessWire\Config;

use function ProcessWire\wire;

/**
 * AttributeBag
 *
 * Specialized ParameterBag for HTML component attributes (class, id, hx-*).
 * Provides convenience methods like addClass(), removeClass(), hasClass(), toggleClass() and can render directly to a string.
 */
class AttributeBag extends ParameterBag
{
	/**
	 * Re-implement constructor to utilize the set method for proper JSON conversion when arrays are provided.
	 *
	 * @param array<string, mixed> $parameters
	 */
	public function __construct(array $parameters = [])
	{
		parent::__construct([]);
		$this->add($parameters);
	}

	/**
	 * Adds parameters, ensuring array/object values are converted to JSON.
	 *
	 * @param array<string, mixed>|ParameterBag $parameters
	 */
	public function add(array|ParameterBag $parameters = []): self
	{
		$parsed = $parameters instanceof ParameterBag ? $parameters->all() : $parameters;
		foreach ($parsed as $key => $value) {
			$isJson = is_array($value) || is_object($value);
			$this->set((string) $key, $value, $isJson);
		}
		return $this;
	}

	/**
	 * Replaces parameters, resetting all attributes first.
	 *
	 * @param array<string, mixed>|ParameterBag $parameters
	 */
	public function replace(array|ParameterBag $parameters = []): self
	{
		$this->parameters = [];
		return $this->add($parameters);
	}

	/**
	 * Add a class or an array of classes to the "class" attribute.
	 *
	 * @param string|array<int, string> $class
	 */
	public function addClass(string|array $class): self
	{
		$currentClasses = $this->getClasses();
		$newClasses = is_array($class) ? $class : explode(' ', $class);

		$merged = array_unique(array_filter(array_merge($currentClasses, $newClasses)));
		parent::set('class', implode(' ', $merged));

		return $this;
	}

	/**
	 * Remove a class or an array of classes from the "class" attribute.
	 *
	 * @param string|array<int, string> $class
	 */
	public function removeClass(string|array $class): self
	{
		$currentClasses = $this->getClasses();
		$targetClasses = is_array($class) ? $class : explode(' ', $class);

		$filtered = array_diff($currentClasses, $targetClasses);

		if (empty($filtered)) {
			$this->remove('class');
		} else {
			parent::set('class', implode(' ', $filtered));
		}

		return $this;
	}

	/**
	 * Check if the attribute bag contains a specific class.
	 */
	public function hasClass(string $class): bool
	{
		$currentClasses = $this->getClasses();
		return in_array(trim($class), $currentClasses, true);
	}

	/**
	 * Toggle a class on or off. Defaults to toggling standard behavior,
	 * but can be forced on (true) or off (false).
	 */
	public function toggleClass(string $class, ?bool $force = null): self
	{
		$hasClass = $this->hasClass($class);

		if ($force === true || ($force === null && !$hasClass)) {
			$this->addClass($class);
		} elseif ($force === false || ($force === null && $hasClass)) {
			$this->removeClass($class);
		}

		return $this;
	}

	/**
	 * Prepend one or more CSS classes to the beginning of the class list.
	 *
	 * @param string|array<int, string> $class
	 */
	public function prependClass(string|array $class): self
	{
		$existing = $this->getClasses();
		$incoming = is_array($class) ? $class : explode(' ', $class);
		$incoming = array_values(array_filter(array_map('trim', $incoming)));

		$merged = array_values(array_unique(array_merge($incoming, $existing)));
		$this->set('class', implode(' ', $merged));

		return $this;
	}

	/** @var array<string, true> Attributes serialized as JSON requiring strict entity encoding */
	private array $jsonKeys = [];

	/**
	 * Normalize and validate an HTML attribute name, rejecting unsafe characters,
	 * raw `on*` DOM event handlers, and `(data-)hx-on*` inline script handlers.
	 */
	public static function normalizeAttributeName(string $name): ?string
	{
		$trimmed = trim($name);
		if ($trimmed === '' || preg_match('/[\x00-\x20\x7F"\'`=\\/<>]/', $trimmed) === 1) {
			return null;
		}
		if (preg_match('/^on[a-z]/i', $trimmed) === 1 || preg_match('/^(?:data-)?hx-on(?:$|:|-)/i', $trimmed) === 1) {
			return null;
		}

		return $trimmed;
	}

	/**
	 * Override set to support automated JSON conversion and HTMX `hx-*` / `data-hx-*` attribute sanitization.
	 */
	public function set(string $key, mixed $value, bool $asJson = false): self
	{
		$normalizedKey = self::normalizeAttributeName($key);
		if ($normalizedKey === null) {
			return $this;
		}

		$lowerKey = strtolower($normalizedKey);
		if (($asJson || $lowerKey === 'hx-config' || $lowerKey === 'data-hx-config') && (is_array($value) || is_object($value))) {
			if ($lowerKey === 'hx-config' || $lowerKey === 'data-hx-config') {
				$value = self::sanitizeHxConfigArray((array) $value);
			}
			$encoded = json_encode(
				$value,
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
			);
			$value = is_string($encoded) ? $encoded : '{}';
			$this->jsonKeys[$normalizedKey] = true;
		} else {
			unset($this->jsonKeys[$normalizedKey]);
			if (is_string($value)) {
				$value = self::sanitizeHxAttributeValue($normalizedKey, $value);
			}
		}
		parent::set($normalizedKey, $value);
		return $this;
	}

	/**
	 * Sanitize an `hx-config` / `data-hx-config` options map against `action:js:...` or cross-origin `credentials:include`.
	 *
	 * @param array<mixed, mixed> $configMap
	 * @return array<string, mixed>
	 */
	private static function sanitizeHxConfigArray(array $configMap): array
	{
		$safe = [];
		foreach ($configMap as $cfgKey => $cfgVal) {
			if (!is_string($cfgKey) || $cfgKey === '' || in_array($cfgKey, ['__proto__', 'constructor', 'prototype'], true)) {
				continue;
			}
			if (strcasecmp($cfgKey, 'action') === 0) {
				if (is_string($cfgVal)) {
					$safe['action'] = self::sanitizeHxUrl($cfgVal);
				}
				continue;
			}
			if (strcasecmp($cfgKey, 'credentials') === 0) {
				$cred = is_string($cfgVal) ? strtolower(trim($cfgVal)) : '';
				$safe['credentials'] = in_array($cred, ['same-origin', 'omit'], true) ? $cred : 'same-origin';
				continue;
			}
			$safe[$cfgKey] = is_array($cfgVal) ? self::sanitizeHxConfigArray($cfgVal) : $cfgVal;
		}

		return $safe;
	}

	/**
	 * Sanitize `hx-*` and `data-hx-*` string attribute values against HTMX v4 `js:` / `javascript:` evaluation prefixes.
	 */
	private static function sanitizeHxAttributeValue(string $attrName, string $value): string
	{
		$lower = strtolower(trim($attrName));
		$hxPart = null;
		if (str_starts_with($lower, 'hx-')) {
			$hxPart = substr($lower, 3);
		} elseif (str_starts_with($lower, 'data-hx-')) {
			$hxPart = substr($lower, 8);
		}
		if ($hxPart === null || $hxPart === '') {
			return $value;
		}

		$baseKey = (string) strtok($hxPart, ':');
		if (in_array($baseKey, ['get', 'post', 'put', 'patch', 'delete', 'action', 'query', 'push-url', 'replace-url'], true)) {
			if (($baseKey === 'push-url' || $baseKey === 'replace-url') && in_array(strtolower(trim($value)), ['true', 'false'], true)) {
				return strtolower(trim($value));
			}
			return self::sanitizeHxUrl($value);
		}

		if ($baseKey === 'confirm') {
			return self::sanitizeConfirmText($value);
		}

		if ($baseKey === 'vals' || $baseKey === 'headers') {
			if (preg_match('/^[\x00-\x20\x7F]*(?:js|javascript):/i', $value) === 1) {
				return '{}';
			}
		}

		if ($baseKey === 'config') {
			$trimmed = trim($value);
			if ($trimmed !== '' && str_starts_with($trimmed, '{')) {
				$decoded = json_decode($trimmed, true, 16);
				if (is_array($decoded)) {
					$sanitized = self::sanitizeHxConfigArray($decoded);
					$reencoded = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

					return is_string($reencoded) ? $reencoded : '{}';
				}
			}
			$compact = preg_replace('/[\x00-\x20\x7F]+/', '', $value) ?? '';
			if (
				preg_match('/(?:js|javascript|data|vbscript|blob|file):/i', $compact) === 1
				|| preg_match('/credentials\s*:\s*[\'"]?include/i', $value) === 1
			) {
				return '{}';
			}
		}

		return $value;
	}

	/**
	 * Convenience method to quickly set the ID attribute.
	 */
	public function id(string $id): self
	{
		return $this->set('id', $id);
	}

	/**
	 * Set a `data-*` attribute on this bag.
	 */
	public function data(string $key, mixed $value, bool $asJson = false): self
	{
		return $this->set("data-{$key}", $value, $asJson);
	}

	/**
	 * Set an `hx-*` attribute on this bag.
	 */
	public function hx(string $key, mixed $value, bool $asJson = false): self
	{
		return $this->set("hx-{$key}", $value, $asJson);
	}

	/**
	 * Harden HTMX URL attributes against executable schemes, protocol-relative URLs,
	 * embedded backslashes, entity-encoded control characters, and cross-origin `http(s)://` URLs.
	 */
	public static function sanitizeHxUrl(string $url): string
	{
		$trimmed = trim($url);
		if ($trimmed === '') {
			return '/';
		}

		$decoded = html_entity_decode($trimmed, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if (
			$decoded === ''
			|| str_contains($trimmed, '\\')
			|| str_contains($decoded, '\\')
			|| preg_match('/[\x00-\x20\x7F]/', $trimmed) === 1
			|| preg_match('/[\x00-\x20\x7F]/', $decoded) === 1
			|| str_starts_with($trimmed, '//')
			|| str_starts_with($decoded, '//')
		) {
			return '/';
		}

		if (preg_match('/^(?:js|javascript|data|vbscript|blob|file):/i', $decoded) === 1) {
			return '/';
		}

		if (preg_match('#^https?://#i', $decoded) !== 1) {
			if (str_contains((string) strtok($decoded, '/?#'), ':')) {
				return '/';
			}

			return $decoded;
		}

		$parts = parse_url($decoded);
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

		$config = wire()?->config;
		if (!$config instanceof Config) {
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

		if (!in_array($hostWithPort, $allowedHosts, true) && !($port === '' && in_array($host, $allowedHosts, true))) {
			return '/';
		}

		return $decoded;
	}

	/**
	 * Neutralize HTMX v4 `js:` / `javascript:` AsyncFunction evaluation prefixes in confirmation prompts.
	 */
	public static function sanitizeConfirmText(string $message): string
	{
		while (preg_match('/^[\x00-\x20\x7F]*(?:js|javascript):/i', $message) === 1) {
			$message = (string) preg_replace('/^[\x00-\x20\x7F]*(?:js|javascript):/i', '', $message);
		}

		return $message;
	}

	/**
	 * Get the current classes as an array.
	 *
	 * @return array<int, string>
	 */
	public function getClasses(): array
	{
		$classString = $this->getString('class');
		return array_values(array_filter(explode(' ', trim($classString))));
	}

	/**
	 * Get the normalized space-separated class string used for shape hashing.
	 */
	public function getClassesKey(): string
	{
		return implode(' ', $this->getClasses());
	}

	/**
	 * Render only the `class="..."` attribute (or empty string when no classes are set).
	 *
	 * When `$leadingSpace` is true and at least one class is present,
	 * the returned string is prefixed with a single space for direct tag interpolation.
	 */
	public function renderClassAttribute(bool $leadingSpace = true): string
	{
		$classes = $this->getClassesKey();
		if ($classes === '') {
			return '';
		}

		return ($leadingSpace ? ' ' : '') . sprintf('class="%s"', htmlspecialchars($classes, ENT_QUOTES, 'UTF-8', false));
	}

	/**
	 * Render all attributes except `class` (such as `id`, `hx-*`, `data-*`, `href`, `aria-*`).
	 *
	 * When `$leadingSpace` is true and at least one non-class attribute is present,
	 * the returned string is prefixed with a single space for direct tag interpolation.
	 */
	public function renderRuntimeAttributes(bool $leadingSpace = true): string
	{
		$html = $this->formatAttributePairs(true);
		if ($html === []) {
			return '';
		}

		return ($leadingSpace ? ' ' : '') . implode(' ', $html);
	}

	/**
	 * Converts the attributes into a valid HTML string (e.g. class="foo" id="bar").
	 */
	public function __toString(): string
	{
		return $this->render();
	}

	/**
	 * Renders attributes into an HTML string.
	 */
	public function render(): string
	{
		return implode(' ', $this->formatAttributePairs(false));
	}

	/**
	 * Format attribute key-value pairs as escaped HTML strings.
	 *
	 * @return list<string>
	 */
	private function formatAttributePairs(bool $excludeClass = false): array
	{
		$html = [];
		foreach ($this->all() as $key => $value) {
			$attrName = self::normalizeAttributeName((string) $key);
			if ($attrName === null) {
				continue;
			}
			if ($excludeClass && $attrName === 'class') {
				continue;
			}
			if ($value === true) {
				$html[] = htmlspecialchars($attrName, ENT_QUOTES, 'UTF-8', false);
			} elseif ($value !== null && $value !== false) {
				$doubleEncode = isset($this->jsonKeys[$attrName]);
				$html[] = sprintf(
					'%s="%s"',
					htmlspecialchars($attrName, ENT_QUOTES, 'UTF-8', false),
					htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8', $doubleEncode)
				);
			}
		}
		return $html;
	}
}
