<?php

declare(strict_types=1);

namespace Totoglu\Htmx\Bag;

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

	/**
	 * Override set to support automated JSON conversion, returning self for fluid interface.
	 */
	public function set(string $key, mixed $value, bool $asJson = false): self
	{
		if ($asJson && (is_array($value) || is_object($value))) {
			$value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
		parent::set($key, $value);
		return $this;
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
	 * Set `hx-action` and `hx-method` on this bag.
	 */
	public function hxAction(string $url, string $httpMethod = 'GET'): self
	{
		return $this->set('hx-action', $url)
			->set('hx-method', strtoupper(trim($httpMethod)));
	}

	public function hxTarget(string $selector): self
	{
		return $this->set('hx-target', $selector);
	}

	public function hxSwap(string $style): self
	{
		return $this->set('hx-swap', $style);
	}

	public function hxInclude(string $selector): self
	{
		return $this->set('hx-include', $selector);
	}

	/**
	 * @param string|array<string, mixed> $values
	 */
	public function hxVals(string|array $values): self
	{
		if (is_array($values)) {
			return $this->set('hx-vals', $values, true);
		}
		return $this->set('hx-vals', $values);
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
			if ($excludeClass && (string) $key === 'class') {
				continue;
			}
			if ($value === true) {
				$html[] = htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8', false);
			} elseif ($value !== null && $value !== false) {
				$html[] = sprintf(
					'%s="%s"',
					htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8', false),
					htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8', false)
				);
			}
		}
		return $html;
	}
}
