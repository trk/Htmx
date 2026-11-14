<?php

declare(strict_types=1);

namespace Totoglu\Htmx;

use InvalidArgumentException;
use ProcessWire\Htmx;
use ProcessWire\ProcessWire;
use ProcessWire\Wire;
use ReflectionMethod;
use Totoglu\Htmx\Bag\AttributeBag;
use Totoglu\Htmx\Bag\ParameterBag;
use Totoglu\Htmx\Cache\HtmxCache;

/**
 * Ui
 *
 * Base class for all stateless visual HTML elements in ProcessWire + HTMX v4.
 * Provides fluent manipulation of ParameterBag and AttributeBag, the complete
 * HTMX v4 attribute helper suite, component-specific Zone/Slot composition,
 * Value Gate short-circuiting, and two-phase Shape Compilation via HtmxCache.
 */
abstract class Ui extends Wire
{
	/** @var list<string> Dangerous HTML tag names blocked from compiled UI open tags */
	private const array BLOCKED_TAG_NAMES = [
		'script',
		'style',
		'iframe',
		'object',
		'embed',
		'base',
		'meta',
		'link',
	];

	private const int MAX_EPHEMERAL_SHAPE_CLOSURES = 512;
	public ParameterBag $parameters;
	public AttributeBag $attributes;

	public ?Component $component = null;

	public ?Ui $parent = null;

	/** @var array<int, Ui|string> */
	public array $children = [];

	public bool $isContainer = false;
	public bool $isElement = true;
	public string $name = 'ui-element';

	/** @var array<string, mixed> Default parameters automatically merged into the element */
	public array $defaultParams = [];

	/**
	 * Structural parameters that control HTML tag hierarchy, layout variants, or CSS classes.
	 * Included in the compiled Shape key.
	 *
	 * @var array<string, mixed>
	 */
	public array $shapeParams = [];

	/**
	 * Dynamic content parameters injected at runtime into compiled templates (`$s[...]`).
	 * Excluded from the compiled Shape key.
	 *
	 * @var array<string, mixed>
	 */
	public array $slotParams = [];

	/**
	 * Valid layout zones declared by this UI component.
	 * May be a flat list (`['left', 'center', 'right']`) or a grouped map
	 * (`['top' => ['top-left', 'top', 'top-right'], 'center' => ['left', 'center', 'right']]`).
	 *
	 * @var array<int|string, string|array<int, string>>
	 */
	protected array $zones = [];

	/**
	 * Default item-to-zone placement map (`['badge' => 'top-left', 'title' => 'center']`).
	 *
	 * @var array<string, string>
	 */
	protected array $defaultZones = [];

	/**
	 * Parameter or slot keys checked by the Value Gate (`hasValue()`).
	 * When non-empty, at least one key (or `$this->children`) must carry a non-empty value
	 * for the element to render.
	 *
	 * @var array<int, string>
	 */
	protected array $valueGateKeys = [];

	protected bool $cacheable = true;
	protected bool $memoize = false;
	protected bool $persistMemo = false;
	protected bool $rawContent = false;
	protected bool $useShapeCache = false;

	/** @var array<string, mixed> Explicitly assigned slot content (`slotName => Ui|string|scalar`) */
	protected array $slotValues = [];

	/** @var array<string, bool> Slot raw-HTML flags (`slotName => bool`) */
	protected array $slotRawFlags = [];

	/** @var array<string, AttributeBag> Named sub-element attribute bags (`attrFor('link')`) */
	protected array $slotAttributes = [];

	/** @var array<string, list<string>> Ordered zone-to-items map (`zone => [item1, item2]`) */
	protected array $zonePlacements = [];

	protected ?Htmx $htmx = null;

	/** @var array<class-string, bool> Cached reflection check for overridden `compileTemplate()` */
	private static array $shapeCompilerRegistry = [];

	/** @var array<string, callable> Per-process compiled shape closure map when `cache(false)` is active */
	private static array $ephemeralShapeClosures = [];

	/** @var array<string, string>|null Per-render cache of resolved runtime slot strings */
	private ?array $resolvedRuntimeSlots = null;

	/** @var string|null Per-render cache of resolved children HTML */
	private ?string $resolvedChildrenHtml = null;

	/** @var array<string, bool> Tracks child UI instances already configured by parent */
	private array $configuredChildren = [];

	/**
	 * Static factory method to instantiate a UI element fluently without the 'new' keyword.
	 *
	 * @param array<string, mixed>|ParameterBag $parameters
	 * @param array<string, mixed>|AttributeBag $attributes
	 */
	public static function make(array|ParameterBag $parameters = [], array|AttributeBag $attributes = []): static
	{
		$instance = new static($parameters, $attributes);
		$wire = $instance->wire();
		if ($wire instanceof ProcessWire) {
			$wire->wire($instance);
		}
		$htmx = $instance->wire()?->htmx;
		$instance->htmx = $htmx instanceof Htmx ? $htmx : null;

		return $instance;
	}

	/**
	 * @param array<string, mixed>|ParameterBag $parameters
	 * @param array<string, mixed>|AttributeBag $attributes
	 */
	public function __construct(array|ParameterBag $parameters = [], array|AttributeBag $attributes = [])
	{
		parent::__construct();
		$wire = $this->wire();
		if ($wire instanceof ProcessWire) {
			$wire->wire($this);
		}
		$htmx = $this->wire()?->htmx;
		$this->htmx = $htmx instanceof Htmx ? $htmx : null;

		$this->parameters = $parameters instanceof ParameterBag
			? $parameters
			: new ParameterBag($parameters);

		$mergedDefaults = array_merge($this->defaultParams, $this->shapeParams, $this->slotParams);
		if ($mergedDefaults !== []) {
			$this->parameters->defaults($mergedDefaults);
		}

		foreach ($this->parameters->all() as $val) {
			if ($val instanceof Ui) {
				$val->parent = $this;
			}
		}

		$this->attributes = $attributes instanceof AttributeBag
			? $attributes
			: new AttributeBag($attributes);

		$this->initZonePlacements();
	}

	/**
	 * Initialize the zone placement map from `$this->zones` and `$this->defaultZones`.
	 */
	protected function initZonePlacements(): void
	{
		$this->zonePlacements = [];
		foreach ($this->getValidZones() as $zone) {
			$this->zonePlacements[$zone] = [];
		}

		foreach ($this->defaultZones as $item => $zone) {
			if (!isset($this->zonePlacements[$zone])) {
				$this->zonePlacements[$zone] = [];
			}
			if (!in_array($item, $this->zonePlacements[$zone], true)) {
				$this->zonePlacements[$zone][] = $item;
			}
		}
	}

	/**
	 * Return all valid zone names declared by this component.
	 *
	 * @return list<string>
	 */
	public function getValidZones(): array
	{
		$valid = [];
		foreach ($this->zones as $key => $value) {
			if (is_array($value)) {
				foreach ($value as $subZone) {
					if (is_string($subZone) && $subZone !== '' && !in_array($subZone, $valid, true)) {
						$valid[] = $subZone;
					}
				}
			} elseif (is_string($value) && $value !== '' && !in_array($value, $valid, true)) {
				$valid[] = $value;
			} elseif (is_string($key) && $key !== '' && !in_array($key, $valid, true)) {
				$valid[] = $key;
			}
		}
		return $valid;
	}

	/**
	 * Return zones normalized into groups (`groupName => list<zoneName>`).
	 *
	 * @return array<string, list<string>>
	 */
	public function getZoneGroups(): array
	{
		$groups = [];
		$flat = [];

		foreach ($this->zones as $key => $value) {
			if (is_string($key) && is_array($value)) {
				$groups[$key] = array_values(array_filter($value, static fn(mixed $z): bool => is_string($z) && $z !== ''));
			} elseif (is_string($value) && $value !== '') {
				$flat[] = $value;
			}
		}

		if ($flat !== []) {
			$groups['default'] = $flat;
		}

		return $groups;
	}

	/**
	 * Check whether the given zone name is supported by this component.
	 */
	public function hasZone(string $zone): bool
	{
		return in_array($zone, $this->getValidZones(), true);
	}

	/**
	 * Place a named item/slot into a target zone with optional ordering (`prepend`, `append`, `before:x`, `after:x`).
	 *
	 * @throws InvalidArgumentException If the target zone is not valid for this component.
	 */
	public function place(string $item, string $zone, ?string $order = null): static
	{
		$validZones = $this->getValidZones();
		if ($validZones !== [] && !in_array($zone, $validZones, true)) {
			throw new InvalidArgumentException(sprintf(
				'Invalid zone "%s" for UI component "%s". Valid zones: %s',
				$zone,
				static::class,
				implode(', ', $validZones)
			));
		}

		// Remove item from any previous zone placement
		foreach ($this->zonePlacements as $z => $items) {
			$idx = array_search($item, $items, true);
			if ($idx !== false) {
				unset($this->zonePlacements[$z][$idx]);
				$this->zonePlacements[$z] = array_values($this->zonePlacements[$z]);
			}
		}

		if (!isset($this->zonePlacements[$zone])) {
			$this->zonePlacements[$zone] = [];
		}

		$list = $this->zonePlacements[$zone];

		if ($order === 'prepend') {
			array_unshift($list, $item);
		} elseif ($order !== null && str_starts_with($order, 'before:')) {
			$target = substr($order, 7);
			$pos = array_search($target, $list, true);
			if ($pos !== false) {
				array_splice($list, (int) $pos, 0, [$item]);
			} else {
				$list[] = $item;
			}
		} elseif ($order !== null && str_starts_with($order, 'after:')) {
			$target = substr($order, 6);
			$pos = array_search($target, $list, true);
			if ($pos !== false) {
				array_splice($list, (int) $pos + 1, 0, [$item]);
			} else {
				$list[] = $item;
			}
		} else {
			$list[] = $item;
		}

		$this->zonePlacements[$zone] = array_values($list);
		$this->invalidateRuntimeCache();

		return $this;
	}

	/**
	 * Return the active ordered map of `zoneName => list<itemKey>`.
	 *
	 * @return array<string, list<string>>
	 */
	public function getZoneMap(): array
	{
		return $this->zonePlacements;
	}

	/**
	 * Assign content (`Ui`, string, scalar, or array of `Ui`/strings) to a named slot,
	 * optionally placing it into a specific zone.
	 */
	public function slot(string $name, mixed $content, ?string $zone = null, bool $raw = false): static
	{
		if ($content instanceof Ui) {
			$content->parent = $this;
		} elseif (is_array($content)) {
			foreach ($content as $item) {
				if ($item instanceof Ui) {
					$item->parent = $this;
				}
			}
		}

		$this->slotValues[$name] = $content;
		$this->slotRawFlags[$name] = $raw;

		if ($zone !== null) {
			$this->place($name, $zone);
		} elseif ($this->hasZone($name) && !$this->isItemPlaced($name)) {
			$this->place($name, $name);
		}

		$this->invalidateRuntimeCache();

		return $this;
	}

	/**
	 * Check whether an item key is already placed in any zone.
	 */
	protected function isItemPlaced(string $item): bool
	{
		foreach ($this->zonePlacements as $items) {
			if (in_array($item, $items, true)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Mark slot content as trusted raw HTML (disables automatic entity escaping for scalar slot content).
	 */
	public function raw(bool $raw = true): static
	{
		$this->rawContent = $raw;
		$this->invalidateRuntimeCache();
		return $this;
	}

	/**
	 * Enable or disable shape/render caching for this element instance.
	 */
	public function cache(bool $enable = true): static
	{
		$this->cacheable = $enable;
		return $this;
	}

	/**
	 * Enable full HTML output memoization for identical leaf UI elements (such as icons or badges).
	 */
	public function memo(bool $enable = true, bool $persist = false): static
	{
		$this->memoize = $enable;
		$this->persistMemo = $persist;
		return $this;
	}

	/**
	 * Retrieve or create a named AttributeBag for a sub-element (e.g., `attrFor('link')`, `attrFor('icon')`).
	 */
	public function attrFor(string $slot): AttributeBag
	{
		if ($slot === 'root' || $slot === '') {
			return $this->attributes;
		}
		if (!isset($this->slotAttributes[$slot])) {
			$this->slotAttributes[$slot] = new AttributeBag();
		}
		return $this->slotAttributes[$slot];
	}

	/**
	 * Fluent alias for `attrFor()`.
	 */
	public function for(string $slot): AttributeBag
	{
		return $this->attrFor($slot);
	}

	/**
	 * Hook allowing a Container UI to configure a child UI (e.g. adding contextual CSS classes)
	 * before the child checks `hasValue()` or computes its own Shape hash.
	 */
	protected function configureChildUi(string $item, Ui $child): void {}

	/**
	 * Ensure `configureChildUi()` runs at most once per child instance during a render cycle.
	 */
	private function ensureChildConfigured(string $item, Ui $child): void
	{
		$hash = $item . ':' . spl_object_id($child);
		if (isset($this->configuredChildren[$hash])) {
			return;
		}
		$this->configuredChildren[$hash] = true;
		$child->parent = $this;
		if ($this->component !== null && $child->component === null) {
			$child->component = $this->component;
		}
		$this->configureChildUi($item, $child);
	}

	/**
	 * Value Gate: Determine whether this element has meaningful content to render.
	 * Executed BEFORE computing any Shape key or touching `HtmxCache`.
	 */
	public function hasValue(): bool
	{
		if ($this->valueGateKeys !== []) {
			foreach ($this->valueGateKeys as $key) {
				$val = $this->getRawSlotOrParamValue($key);
				if ($this->isMeaningfulValue($key, $val)) {
					return true;
				}
			}
			foreach ($this->children as $idx => $child) {
				if ($this->isMeaningfulValue('child_' . $idx, $child)) {
					return true;
				}
			}
			return false;
		}

		if ($this->zones !== []) {
			$slots = $this->getResolvedRuntimeSlots();
			foreach ($this->zonePlacements as $items) {
				foreach ($items as $itemKey) {
					if (($slots[$itemKey] ?? '') !== '') {
						return true;
					}
				}
			}
			foreach ($slots as $val) {
				if ($val !== '') {
					return true;
				}
			}
			if ($this->getResolvedChildrenHtml() !== '') {
				return true;
			}
			return false;
		}

		return true;
	}

	/**
	 * Render this UI element only if `renderReady()` and `hasValue()` are true;
	 * otherwise return `''` immediately without touching `HtmxCache`.
	 */
	public function renderIfHasValue(): string
	{
		return (string) $this;
	}

	/**
	 * Check whether a raw slot/parameter/child value is non-empty.
	 */
	protected function isMeaningfulValue(string $key, mixed $val): bool
	{
		if ($val === null || $val === false) {
			return false;
		}
		if ($val instanceof Ui) {
			$this->ensureChildConfigured($key, $val);
			return $val->renderReady() && $val->hasValue();
		}
		if (is_array($val)) {
			foreach ($val as $subIdx => $item) {
				if ($this->isMeaningfulValue($key . '_' . $subIdx, $item)) {
					return true;
				}
			}
			return false;
		}
		if (is_bool($val)) {
			return $val;
		}
		return trim((string) $val) !== '';
	}

	/**
	 * Retrieve a raw value from `$this->slotValues` or `$this->parameters`.
	 */
	protected function getRawSlotOrParamValue(string $key): mixed
	{
		if (array_key_exists($key, $this->slotValues)) {
			return $this->slotValues[$key];
		}
		if ($this->parameters->has($key)) {
			return $this->parameters->get($key);
		}
		return null;
	}

	/**
	 * Resolve all runtime slot values into safe HTML strings once per render cycle.
	 * Child `Ui` instances are evaluated via `renderIfHasValue()` so each child uses its own shape cache.
	 *
	 * @return array<string, string>
	 */
	protected function getResolvedRuntimeSlots(): array
	{
		if ($this->resolvedRuntimeSlots !== null) {
			return $this->resolvedRuntimeSlots;
		}

		$keys = array_unique(array_merge(
			array_keys($this->slotParams),
			array_keys($this->slotValues),
			array_keys($this->defaultZones)
		));

		foreach ($this->zonePlacements as $items) {
			foreach ($items as $itemKey) {
				if (!in_array($itemKey, $keys, true)) {
					$keys[] = $itemKey;
				}
			}
		}

		// Include any additional non-shape parameters passed into ParameterBag
		foreach ($this->parameters->all() as $paramKey => $paramVal) {
			if (!array_key_exists($paramKey, $this->shapeParams) && !in_array($paramKey, $keys, true)) {
				$keys[] = $paramKey;
			}
		}

		$resolved = [];
		foreach ($keys as $key) {
			$rawVal = $this->getRawSlotOrParamValue($key);
			$isRaw = $this->slotRawFlags[$key] ?? $this->rawContent;
			$resolved[$key] = $this->resolveSingleSlotValue($key, $rawVal, $isRaw);
		}

		$this->resolvedRuntimeSlots = $resolved;
		return $resolved;
	}

	/**
	 * Convert a single slot/parameter value (`Ui`, array, or scalar) into a rendered HTML string.
	 */
	protected function resolveSingleSlotValue(string $key, mixed $value, bool $isRaw): string
	{
		if ($value === null || $value === false) {
			return '';
		}

		if ($value instanceof Ui) {
			$this->ensureChildConfigured($key, $value);
			return $value->renderIfHasValue();
		}

		if (is_array($value)) {
			$parts = [];
			foreach ($value as $idx => $item) {
				$rendered = $this->resolveSingleSlotValue($key . '_' . $idx, $item, $isRaw);
				if ($rendered !== '') {
					$parts[] = $rendered;
				}
			}
			return implode('', $parts);
		}

		$str = (string) $value;
		if (trim($str) === '') {
			return '';
		}

		return $isRaw ? $str : $this->esc($str);
	}

	/**
	 * Resolve `$this->children` into an HTML string once per render cycle.
	 */
	protected function getResolvedChildrenHtml(): string
	{
		if ($this->resolvedChildrenHtml !== null) {
			return $this->resolvedChildrenHtml;
		}

		$html = '';
		foreach ($this->children as $idx => $child) {
			if ($child instanceof Ui) {
				$this->ensureChildConfigured('child_' . $idx, $child);
				$html .= $child->renderIfHasValue();
			} else {
				$str = (string) $child;
				if ($str !== '') {
					$html .= $str;
				}
			}
		}

		$this->resolvedChildrenHtml = $html;
		return $html;
	}

	/**
	 * Reset per-render cached slot/children strings when state mutates.
	 */
	protected function invalidateRuntimeCache(): void
	{
		$this->resolvedRuntimeSlots = null;
		$this->resolvedChildrenHtml = null;
		$this->configuredChildren = [];
	}

	public function getId(): string
	{
		if (!$this->attributes->has('id')) {
			$uniquePath = substr(md5(uniqid('', true)), 0, 6);
			$this->attributes->set('id', "{$this->name}-{$uniquePath}");
		}
		return (string) $this->attributes->get('id');
	}

	public function param(string $key, mixed $value = null): mixed
	{
		if (func_num_args() === 1) {
			return $this->parameters->get($key);
		}

		return $this->setParam($key, $value);
	}

	protected function esc(mixed $value): string
	{
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8', false);
	}

	public function setParam(string $key, mixed $value): self
	{
		if ($value instanceof Ui) {
			$value->parent = $this;
		}
		$this->parameters->set($key, $value);
		$this->invalidateRuntimeCache();
		return $this;
	}

	public function setAttribute(string $key, mixed $value, bool $asJson = false): self
	{
		$this->attributes->set($key, $value, $asJson);
		return $this;
	}

	public function hasAttribute(string $key): bool
	{
		return $this->attributes->has($key);
	}

	public function getAttribute(string $key, mixed $default = null): mixed
	{
		return $this->attributes->get($key, $default);
	}

	/**
	 * @param string|array<int, string> $class
	 */
	public function addClass(string|array $class): self
	{
		$this->attributes->addClass($class);
		return $this;
	}

	/**
	 * @param string|array<int, string> $class
	 */
	public function prependClass(string|array $class): self
	{
		$this->attributes->prependClass($class);
		return $this;
	}

	/**
	 * @param string|array<int, string> $class
	 */
	public function removeClass(string|array $class): self
	{
		$this->attributes->removeClass($class);
		return $this;
	}

	public function data(string $key, mixed $value, bool $asJson = false): self
	{
		return $this->setAttribute("data-{$key}", $value, $asJson);
	}

	/**
	 * Set a raw hx-* attribute dynamically.
	 *
	 * Accepts HTMX v4 modifier suffixes directly on the key:
	 *   $ui->hx('confirm:inherited', 'Are you sure?');
	 *   $ui->hx('include:inherited:append', '.extra-fields');
	 */
	public function hx(string $key, mixed $value, bool $asJson = false): self
	{
		return $this->setAttribute("hx-{$key}", $value, $asJson);
	}

	/**
	 * HTMX v4: hx-action — set the request URL (use alongside hx-method).
	 */
	public function action(string $url): self
	{
		return $this->setAttribute('hx-action', $url);
	}

	/**
	 * HTMX v4: hx-method — set the HTTP method (get, post, put, delete, patch, query, ...).
	 */
	public function method(string $httpMethod): self
	{
		return $this->setAttribute('hx-method', strtolower(trim($httpMethod)));
	}

	/**
	 * HTMX v4: hx-query — issue a QUERY request (body-borne search params).
	 */
	public function query(string $url): self
	{
		return $this->setAttribute('hx-query', $url);
	}

	/**
	 * HTMX v4: hx-config — per-element request configuration.
	 *
	 * Accepts either a raw HCON/JSON string or an array which will be JSON-encoded automatically.
	 *
	 * @param string|array<string, mixed> $value
	 */
	public function config(string|array $value): self
	{
		if (is_array($value)) {
			return $this->setAttribute('hx-config', $value, true);
		}
		return $this->setAttribute('hx-config', $value);
	}

	/**
	 * HTMX v4: hx-ignore — tell htmx to skip processing this element and its subtree.
	 */
	public function ignore(bool $ignore = true): self
	{
		if ($ignore) {
			return $this->setAttribute('hx-ignore', true);
		}
		$this->attributes->remove('hx-ignore');
		return $this;
	}

	/**
	 * HTMX v4: hx-disable — disable matching elements during a request.
	 */
	public function disable(bool|string $selector = true): self
	{
		if ($selector === false) {
			$this->attributes->remove('hx-disable');
			return $this;
		}
		return $this->setAttribute('hx-disable', is_string($selector) ? $selector : true);
	}

	/**
	 * HTMX v4: hx-validate — control native HTML5 form validation behavior for this element.
	 */
	public function validate(bool|string $value = true): self
	{
		if ($value === false) {
			$this->attributes->remove('hx-validate');
			return $this;
		}
		return $this->setAttribute('hx-validate', is_string($value) ? $value : true);
	}

	/**
	 * HTMX v4: hx-status:<code|pattern> — set per-status swap/target/select/push/replace/transition behavior.
	 */
	public function status(int|string $code, string $config): self
	{
		$codeStr = is_int($code) ? (string) $code : $code;
		return $this->setAttribute("hx-status:{$codeStr}", $config);
	}

	/**
	 * HTMX v4: Configure this element as a component action submit trigger.
	 * Sets: type="submit" name="__htmx_action" value="$actionName"
	 */
	public function actionTrigger(string $actionName): self
	{
		return $this->setAttribute('type', 'submit')
			->setAttribute('name', '__htmx_action')
			->setAttribute('value', $actionName);
	}

	/**
	 * HTMX v4: hx-target — CSS selector (or extended selector like `this`, `closest div`) for swap target.
	 */
	public function target(string $selector): self
	{
		return $this->setAttribute('hx-target', $selector);
	}

	/**
	 * HTMX v4: hx-swap — swap strategy (e.g. `outerMorph`, `innerMorph`, `outerSync`, `textContent`, `delete`, `none`).
	 */
	public function swap(string $style): self
	{
		return $this->setAttribute('hx-swap', $style);
	}

	/**
	 * HTMX v4: hx-select — CSS selector to pick a sub-fragment from the server response.
	 */
	public function select(string $selector): self
	{
		return $this->setAttribute('hx-select', $selector);
	}

	/**
	 * HTMX v4: hx-select-oob — CSS selector(s) to pick out elements for OOB swapping.
	 */
	public function selectOob(string $selector): self
	{
		return $this->setAttribute('hx-select-oob', $selector);
	}

	/**
	 * HTMX v4: hx-vals — add static/JSON/HCON or `js:` dynamic values to the request.
	 *
	 * @param string|array<string, mixed> $values
	 */
	public function vals(string|array $values): self
	{
		if (is_array($values)) {
			return $this->setAttribute('hx-vals', $values, true);
		}
		return $this->setAttribute('hx-vals', $values);
	}

	/**
	 * HTMX v4: hx-headers — add custom headers to the request.
	 *
	 * @param string|array<string, mixed> $headers
	 */
	public function headers(string|array $headers): self
	{
		if (is_array($headers)) {
			return $this->setAttribute('hx-headers', $headers, true);
		}
		return $this->setAttribute('hx-headers', $headers);
	}

	/**
	 * HTMX v4: hx-boost — progressive enhancement for links and forms.
	 */
	public function boost(bool $boost = true): self
	{
		return $this->setAttribute('hx-boost', $boost ? 'true' : 'false');
	}

	/**
	 * HTMX v4: hx-confirm — show confirmation dialog before issuing the request.
	 */
	public function confirm(string $message): self
	{
		return $this->setAttribute('hx-confirm', $message);
	}

	/**
	 * HTMX v4: hx-prompt — prompt user for input before issuing the request (requires hx-prompt extension).
	 */
	public function prompt(string $message): self
	{
		return $this->setAttribute('hx-prompt', $message);
	}

	/**
	 * HTMX v4: hx-indicator — CSS selector for the loading indicator element.
	 */
	public function indicator(string $selector): self
	{
		return $this->setAttribute('hx-indicator', $selector);
	}

	/**
	 * HTMX v4: hx-include — CSS selector for additional elements whose values should be included.
	 */
	public function include(string $selector): self
	{
		return $this->setAttribute('hx-include', $selector);
	}

	/**
	 * HTMX v4: hx-params — filter parameters sent with the request (`*`, `none`, `not <list>`, `<list>`).
	 */
	public function params(string $filter): self
	{
		return $this->setAttribute('hx-params', $filter);
	}

	/**
	 * HTMX v4: hx-trigger — specify the event(s) and modifiers that trigger the request.
	 */
	public function trigger(string $spec): self
	{
		return $this->setAttribute('hx-trigger', $spec);
	}

	/**
	 * HTMX v4: hx-sync — synchronize requests across elements (e.g. `#form:abort`).
	 */
	public function sync(string $spec): self
	{
		return $this->setAttribute('hx-sync', $spec);
	}

	/**
	 * HTMX v4: hx-encoding — set request encoding (e.g. `multipart/form-data` for file uploads).
	 */
	public function encoding(string $encoding = 'multipart/form-data'): self
	{
		return $this->setAttribute('hx-encoding', $encoding)
			->setAttribute('enctype', $encoding);
	}

	/**
	 * HTMX v4: hx-push-url — push the request URL (or custom URL) into browser history.
	 */
	public function pushUrl(string|bool $url = true): self
	{
		$val = is_bool($url) ? ($url ? 'true' : 'false') : $url;
		return $this->setAttribute('hx-push-url', $val);
	}

	/**
	 * HTMX v4: hx-replace-url — replace the current URL in browser history.
	 */
	public function replaceUrl(string|bool $url = true): self
	{
		$val = is_bool($url) ? ($url ? 'true' : 'false') : $url;
		return $this->setAttribute('hx-replace-url', $val);
	}

	/**
	 * HTMX v4: hx-preserve — keep element unchanged across swaps.
	 */
	public function preserve(bool $preserve = true): self
	{
		if ($preserve) {
			return $this->setAttribute('hx-preserve', true);
		}
		$this->attributes->remove('hx-preserve');
		return $this;
	}

	public function renderState(): string
	{
		if ($this->component !== null) {
			return $this->component->renderStatePayload();
		}
		return '';
	}

	public function hyperscript(string $script): self
	{
		$cleanScript = preg_replace('/\s+/', ' ', trim($script));
		return $this->setAttribute('_', is_string($cleanScript) ? $cleanScript : $script);
	}

	public function __(string $text, string $textdomain = 'site-htmx'): string
	{
		try {
			$func = '\ProcessWire\__';
			if (function_exists($func)) {
				return (string) $func($text, $textdomain);
			}
		} catch (\Throwable) {
			// fall through
		}
		return $text;
	}

	/**
	 * Determine whether this UI subclass uses two-phase Shape Compilation (`compileTemplate()`).
	 */
	protected function usesShapeCache(): bool
	{
		if ($this->useShapeCache) {
			return true;
		}
		$class = static::class;
		if (!isset(self::$shapeCompilerRegistry[$class])) {
			$ref = new ReflectionMethod($class, 'compileTemplate');
			self::$shapeCompilerRegistry[$class] = ($ref->getDeclaringClass()->getName() !== self::class);
		}
		return self::$shapeCompilerRegistry[$class];
	}

	/**
	 * Build the deterministic array of structural values that uniquely identify this element's HTML shape.
	 * Dynamic slot values (`$slotParams`) and runtime attributes (`hx-*`, `data-*`, `id`, `href`) are excluded.
	 *
	 * @return array<string, mixed>
	 */
	protected function buildShapeKeyValues(): array
	{
		$shapeValues = [];
		foreach ($this->shapeParams as $key => $defaultVal) {
			$val = $this->parameters->get($key, $defaultVal);
			if (is_scalar($val) || is_array($val) || $val === null) {
				$shapeValues[$key] = $val;
			}
		}

		$shapeValues['__classes'] = $this->attributes->getClassesKey();

		if ($this->slotAttributes !== []) {
			$slotClassKeys = [];
			foreach ($this->slotAttributes as $slotName => $bag) {
				$ck = $bag->getClassesKey();
				if ($ck !== '') {
					$slotClassKeys[$slotName] = $ck;
				}
			}
			if ($slotClassKeys !== []) {
				ksort($slotClassKeys);
				$shapeValues['__slot_classes'] = $slotClassKeys;
			}
		}

		if ($this->zonePlacements !== []) {
			$shapeValues['__zones'] = $this->zonePlacements;
		}

		$shapeValues['__lang'] = $this->resolveActiveLanguageId();

		return $shapeValues;
	}

	/**
	 * Resolve the active ProcessWire user language ID (`0` when multi-language is inactive).
	 */
	private function resolveActiveLanguageId(): int
	{
		$user = $this->wire()?->user;
		if ($user !== null && isset($user->language) && is_object($user->language) && isset($user->language->id)) {
			return (int) $user->language->id;
		}

		return 0;
	}

	/**
	 * Phase 1 (Cache Miss Only): Resolve structural parameters, CSS classes, and zone maps
	 * needed by `compileTemplate(array $shape)`.
	 *
	 * @return array<string, mixed>
	 */
	protected function resolveShape(): array
	{
		$shape = [];
		foreach ($this->shapeParams as $key => $defaultVal) {
			$shape[$key] = $this->parameters->get($key, $defaultVal);
		}
		$shape['classAttr'] = $this->attributes->renderClassAttribute();
		$shape['zoneMap'] = $this->getZoneMap();
		$shape['zoneGroups'] = $this->getZoneGroups();

		$slotClassAttrs = [];
		foreach ($this->slotAttributes as $slotName => $bag) {
			$slotClassAttrs[$slotName] = $bag->renderClassAttribute();
		}
		$shape['slotClassAttrs'] = $slotClassAttrs;

		return $shape;
	}

	/**
	 * Phase 1 (Cache Miss Only): Return a PHP closure source string:
	 * `static function (array $s, array $attrs, string $children): string { ... }`
	 *
	 * @param array<string, mixed> $shape
	 */
	protected function compileTemplate(array $shape): string
	{
		$open = self::compileOpenTag('div', (string) ($shape['classAttr'] ?? ''));
		return "static function (array \$s, array \$attrs, string \$children): string {\n"
			. "\treturn {$open} . \$children . '</div>';\n"
			. "}";
	}

	/**
	 * Sanitize an HTML tag name for safe interpolation in compiled UI templates.
	 */
	protected static function sanitizeTagName(string $tag, string $fallback = 'div'): string
	{
		$trimmed = trim($tag);
		if (
			$trimmed === ''
			|| preg_match('/^[a-zA-Z][a-zA-Z0-9\-]*$/', $trimmed) !== 1
			|| in_array(strtolower($trimmed), self::BLOCKED_TAG_NAMES, true)
		) {
			return $fallback;
		}
		return $trimmed;
	}

	/**
	 * Helper for `compileTemplate()`: generate PHP expression for an opening HTML tag with baked class attribute
	 * and runtime attributes from `$attrs[$attrSlot]`.
	 */
	protected static function compileOpenTag(string $tag, string $classAttr, string $attrSlot = 'root'): string
	{
		$safeTag = self::sanitizeTagName($tag);
		$prefix = '<' . $safeTag . $classAttr;
		return var_export($prefix, true) . ' . ($attrs[' . var_export($attrSlot, true) . '] ?? \'\') . \'>\'';
	}

	/**
	 * Helper for `compileTemplate()`: generate PHP expression that concatenates a list of slot keys from `$s`.
	 *
	 * @param list<string> $items
	 */
	protected static function compileZoneItemsExpr(array $items): string
	{
		if ($items === []) {
			return "''";
		}
		$parts = [];
		foreach ($items as $item) {
			$parts[] = '($s[' . var_export($item, true) . '] ?? \'\')';
		}
		return implode(' . ', $parts);
	}

	/**
	 * Build runtime attribute strings (`hx-*`, `data-*`, `id`, `href`, etc.) for root and named sub-elements.
	 *
	 * @return array<string, string>
	 */
	protected function buildRuntimeAttributesMap(): array
	{
		$map = [
			'root' => $this->attributes->renderRuntimeAttributes(true),
		];
		foreach ($this->slotAttributes as $slotName => $bag) {
			$map[$slotName] = $bag->renderRuntimeAttributes(true);
		}
		return $map;
	}

	/**
	 * Resolve the active `HtmxCache` instance.
	 */
	protected function getHtmxCache(): ?HtmxCache
	{
		return HtmxCache::resolve($this->htmx);
	}

	/**
	 * Execute the two-phase compiled Shape closure via `HtmxCache`.
	 */
	protected function renderCompiledShape(): string
	{
		$slots = $this->getResolvedRuntimeSlots();
		$childrenHtml = $this->getResolvedChildrenHtml();
		$runtimeAttrs = $this->buildRuntimeAttributesMap();

		$shapeKeyValues = $this->buildShapeKeyValues();
		$cache = $this->cacheable ? $this->getHtmxCache() : null;
		if ($cache === null) {
			$ephemeralKey = static::class . '|' . hash('xxh128', (string) json_encode($shapeKeyValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
			if (!isset(self::$ephemeralShapeClosures[$ephemeralKey])) {
				$shape = $this->resolveShape();
				$code = trim($this->compileTemplate($shape));
				if (str_ends_with($code, ';')) {
					$code = substr($code, 0, -1);
				}
				/** @var callable $compiled */
				$compiled = eval('return ' . $code . ';');
				if (count(self::$ephemeralShapeClosures) >= self::MAX_EPHEMERAL_SHAPE_CLOSURES) {
					array_shift(self::$ephemeralShapeClosures);
				}
				self::$ephemeralShapeClosures[$ephemeralKey] = $compiled;
			}

			$closure = self::$ephemeralShapeClosures[$ephemeralKey];
			return (string) $closure($slots, $runtimeAttrs, $childrenHtml);
		}

		$closure = $cache->getOrCompileUi(
			static::class,
			$shapeKeyValues,
			function (): string {
				$shape = $this->resolveShape();
				return $this->compileTemplate($shape);
			}
		);

		return (string) $closure($slots, $runtimeAttrs, $childrenHtml);
	}

	/**
	 * Render this UI element. Subclasses may either override `render()` directly
	 * or override `compileTemplate(array $shape)` for two-phase Shape Compilation.
	 */
	public function render(): string
	{
		if ($this->usesShapeCache()) {
			return $this->renderCompiledShape();
		}
		return $this->getResolvedChildrenHtml();
	}

	protected function beforeRender(): void {}

	protected function afterRender(string &$html): void {}

	public function addChild(Ui|string $child): self
	{
		if ($child instanceof Ui) {
			$child->parent = $this;
		}
		$this->children[] = $child;
		$this->invalidateRuntimeCache();
		return $this;
	}

	/**
	 * @param array<int, Ui|string> $children
	 */
	public function addChildren(array $children): self
	{
		foreach ($children as $child) {
			$this->addChild($child);
		}
		return $this;
	}

	public function findChild(?string $name, ?string $paramKey = null, mixed $paramValue = null): ?Ui
	{
		foreach ($this->children as $child) {
			if ($child instanceof Ui) {
				$nameMatches = ($name === null || $child->name === $name);
				$paramMatches = ($paramKey === null || $child->param($paramKey) === $paramValue);

				if ($nameMatches && $paramMatches) {
					return $child;
				}

				$nested = $child->findChild($name, $paramKey, $paramValue);
				if ($nested !== null) {
					return $nested;
				}
			}
		}
		return null;
	}

	public function getChild(int $index): Ui|string|null
	{
		return $this->children[$index] ?? null;
	}

	/**
	 * @return array<int, Ui|string>
	 */
	public function getChildren(): array
	{
		return $this->children;
	}

	public function renderChild(?string $name, ?string $paramKey = null, mixed $paramValue = null): string
	{
		$child = $this->findChild($name, $paramKey, $paramValue);
		return $child !== null ? (string) $child : '';
	}

	public function renderChildren(): string
	{
		return $this->getResolvedChildrenHtml();
	}

	public function getParent(): ?Ui
	{
		return $this->parent;
	}

	public function findParent(?string $name, ?string $paramKey = null, mixed $paramValue = null): ?Ui
	{
		$current = $this->parent;
		while ($current !== null) {
			if ($name === null) {
				$nameMatches = true;
			} else {
				$class = get_class($current);
				$shortClass = basename(str_replace('\\', '/', $class));
				$nameMatches = ($current->name === $name || $class === $name || $shortClass === $name);
			}

			$paramMatches = ($paramKey === null || $current->param($paramKey) === $paramValue);

			if ($nameMatches && $paramMatches) {
				return $current;
			}

			$current = $current->getParent();
		}

		return null;
	}

	public function count(): int
	{
		return count($this->children);
	}

	public function renderReady(): bool
	{
		return true;
	}

	/**
	 * Execute the full render lifecycle (Value Gate -> beforeRender -> Memo/Shape Cache/render -> afterRender)
	 * and let exceptions bubble up.
	 */
	public function renderToString(): string
	{
		try {
			if (!$this->renderReady() || !$this->hasValue()) {
				return '';
			}

			$this->beforeRender();

			$cache = ($this->cacheable && $this->memoize) ? $this->getHtmxCache() : null;
			$memoKey = null;

			if ($cache !== null) {
				$memoPayload = [
					'shape' => $this->buildShapeKeyValues(),
					'slots' => $this->getResolvedRuntimeSlots(),
					'attrs' => $this->buildRuntimeAttributesMap(),
					'children' => $this->getResolvedChildrenHtml(),
					'lang' => $this->resolveActiveLanguageId(),
				];
				$memoKey = hash('xxh128', (string) json_encode($memoPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
				$cachedMemo = $cache->getMemo(static::class, $memoKey);
				if ($cachedMemo !== null) {
					return $cachedMemo;
				}
			}

			$html = $this->usesShapeCache()
				? $this->renderCompiledShape()
				: $this->render();

			$this->afterRender($html);

			if ($cache !== null && $memoKey !== null) {
				$cache->putMemo(static::class, $memoKey, $html, $this->persistMemo);
			}

			return $html;
		} finally {
			$this->invalidateRuntimeCache();
		}
	}

	public function __toString(): string
	{
		try {
			return $this->renderToString();
		} catch (\Throwable $e) {
			$this->wire()?->log?->error(
				'HTMX UI Rendering Error (' . static::class . '): ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
			);
			$config = $this->wire()?->config;
			if ($config !== null && !empty($config->debug)) {
				return '<!-- HTMX UI Rendering Error: ' . htmlspecialchars($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), ENT_QUOTES, 'UTF-8') . ' -->';
			}
			return '';
		}
	}
}
