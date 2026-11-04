<?php

declare(strict_types=1);

namespace Totoglu\Htmx;

use ProcessWire\Htmx;
use Totoglu\Htmx\Bag\ParameterBag;
use Totoglu\Htmx\Bag\AttributeBag;

use function ProcessWire\wire;

/**
 * Ui
 *
 * Base class for all stateless visual HTML elements in ProcessWire + HTMX v4.
 * Provides fluent manipulation of ParameterBag and AttributeBag plus the complete
 * HTMX v4 attribute helper suite.
 */
abstract class Ui
{
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

	protected ?Htmx $htmx = null;

	/**
	 * Static factory method to instantiate a UI element fluently without the 'new' keyword.
	 *
	 * @param array<string, mixed>|ParameterBag $parameters
	 * @param array<string, mixed>|AttributeBag $attributes
	 */
	public static function make(array|ParameterBag $parameters = [], array|AttributeBag $attributes = []): static
	{
		return new static($parameters, $attributes);
	}

	/**
	 * @param array<string, mixed>|ParameterBag $parameters
	 * @param array<string, mixed>|AttributeBag $attributes
	 */
	public function __construct(array|ParameterBag $parameters = [], array|AttributeBag $attributes = [])
	{
		$htmx = wire()->htmx;
		$this->htmx = $htmx instanceof Htmx ? $htmx : null;

		$this->parameters = $parameters instanceof ParameterBag
			? $parameters
			: new ParameterBag($parameters);

		$this->parameters->defaults($this->defaultParams);

		$this->attributes = $attributes instanceof AttributeBag
			? $attributes
			: new AttributeBag($attributes);
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

		$this->parameters->set($key, $value);
		return $this;
	}

	protected function esc(mixed $value): string
	{
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8', false);
	}

	public function setParam(string $key, mixed $value): self
	{
		$this->parameters->set($key, $value);
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
	 * HTMX v4: hx-action + hx-method shorthand.
	 */
	public function hxAction(string $url, string $httpMethod = 'GET'): self
	{
		return $this->setAttribute('hx-action', $url)
			->setAttribute('hx-method', strtoupper(trim($httpMethod)));
	}

	public function hxMethod(string $httpMethod): self
	{
		return $this->setAttribute('hx-method', strtoupper(trim($httpMethod)));
	}

	public function hxTarget(string $selector): self
	{
		return $this->target($selector);
	}

	public function hxSwap(string $style): self
	{
		return $this->swap($style);
	}

	public function hxInclude(string $selector): self
	{
		return $this->include($selector);
	}

	/**
	 * @param string|array<string, mixed> $values
	 */
	public function hxVals(string|array $values): self
	{
		return $this->vals($values);
	}

	public function hxConfirm(string $message): self
	{
		return $this->confirm($message);
	}

	public function hxTrigger(string $spec): self
	{
		return $this->trigger($spec);
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

	public function _(string $script): self
	{
		return $this->hyperscript($script);
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

	abstract public function render(): string;

	protected function beforeRender(): void {}

	protected function afterRender(string &$html): void {}

	public function addChild(Ui|string $child): self
	{
		if ($child instanceof Ui) {
			$child->parent = $this;
		}
		$this->children[] = $child;
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
		$html = '';
		foreach ($this->children as $child) {
			$html .= (string) $child;
		}
		return $html;
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

	public function __toString(): string
	{
		try {
			if (!$this->renderReady()) {
				return '';
			}

			$this->beforeRender();
			$html = $this->render();
			$this->afterRender($html);
			return $html;
		} catch (\Throwable $e) {
			$config = wire()->config;
			if ($config !== null && $config->debug) {
				return "<div class='uk-alert uk-alert-danger'><h4>Error Rendering HTMX UI</h4><p>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p></div>";
			}
			return '';
		}
	}
}
