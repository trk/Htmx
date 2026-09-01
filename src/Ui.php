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
 * Base class for all visual HTML components. Provides easy manipulation of ParameterBag
 * and AttributeBag to render clean, safe HTML without string concatenation mess.
 *
 * HTMX v4 helpers added:
 *   action(), method(), query(), config(), ignore(), validate(), status()
 *   The hx() helper accepts :inherited and :append modifier suffixes on the key.
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

    /** @var array<string, mixed> Default parameters automatically merged into the component */
    public array $defaultParams = [];

    protected ?Htmx $htmx = null;

    /**
     * Static factory method to instantiate a component fluently without the 'new' keyword.
     */
    public static function make(array|ParameterBag $parameters = [], array|AttributeBag $attributes = []): static
    {
        return new static($parameters, $attributes);
    }

    public function __construct(array|ParameterBag $parameters = [], array|AttributeBag $attributes = [])
    {
        $this->htmx = wire('htmx');

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
        return $this->attributes->get('id');
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
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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

    public function addClass(string|array $class): self
    {
        $this->attributes->addClass($class);
        return $this;
    }

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
     * @deprecated Use hx() instead — the "htmx" prefix was never a standard attribute.
     */
    public function htmx(string $key, mixed $value, bool $asJson = false): self
    {
        return $this->setAttribute("htmx-{$key}", $value, $asJson);
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
     *
     * Example: $ui->action('/save')->method('POST');
     */
    public function action(string $url): self
    {
        return $this->setAttribute('hx-action', $url);
    }

    /**
     * HTMX v4: hx-method — set the HTTP method (GET, POST, PUT, DELETE, PATCH, QUERY, ...).
     */
    public function method(string $httpMethod): self
    {
        return $this->setAttribute('hx-method', strtoupper($httpMethod));
    }

    /**
     * HTMX v4: hx-query — issue a QUERY request (body-borne search params, like a POST body for search).
     */
    public function query(string $url): self
    {
        return $this->setAttribute('hx-query', $url);
    }

    /**
     * HTMX v4: hx-config — per-element request configuration.
     *
     * Accepts either a raw string ("timeout:5000 history:false") or an array which
     * will be JSON-encoded automatically.
     *
     * Example:
     *   $ui->config(['timeout' => 5000, 'credentials' => true]);
     *   $ui->config('timeout:5000 history:false');
     */
    public function config(string|array $value): self
    {
        if (is_array($value)) {
            return $this->setAttribute('hx-config', $value, true);
        }
        return $this->setAttribute('hx-config', $value);
    }

    /**
     * HTMX v4: hx-ignore — tell htmx to skip processing this element entirely.
     *
     * (Replaces the old htmx 2 meaning of `hx-disable`.)
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
     *
     * (Replaces the old htmx 2 `hx-disabled-elt` attribute.)
     *
     * Accepts a CSS selector, or pass `true` / no argument for the nearest form/button convention.
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
     * HTMX v4: hx-validate — control native form validation behavior for this element.
     *
     * Pass `true` to force validation, `false` to skip it, or a raw modifier string.
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
     * HTMX v4: hx-status — set per-status swap/target/push/replace/transition behavior.
     *
     * Examples:
     *   $ui->status(422, 'swap:innerHTML target:#errors');
     *   $ui->status('5xx', 'swap:none push:false');
     */
    public function status(int|string $code, string $config): self
    {
        $code = is_int($code) ? (string) $code : $code;
        return $this->setAttribute("hx-status:{$code}", $config);
    }

    /**
     * Helper to configure this element as an action trigger.
     * Sets: name="hx__action" value="$actionName"
     */
    public function actionTrigger(string $actionName): self
    {
        return $this->setAttribute('name', 'hx__action')
            ->setAttribute('value', $actionName);
    }

    public function renderState(): string
    {
        if ($this->component) {
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
        } catch (\Throwable $e) {
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

                if ($nested = $child->findChild($name, $paramKey, $paramValue)) {
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

    public function getChildren(): array
    {
        return $this->children;
    }

    public function renderChild(?string $name, ?string $paramKey = null, mixed $paramValue = null): string
    {
        $child = $this->findChild($name, $paramKey, $paramValue);
        return $child ? (string)$child : '';
    }

    public function renderChildren(): string
    {
        $html = '';
        foreach ($this->children as $child) {
            $html .= (string)$child;
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
            $nameMatches = false;
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
            $config = wire('config');
            if ($config && $config->debug) {
                return "<div class='uk-alert uk-alert-danger'><h4>Error Rendering HTMX UI</h4><p>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p></div>";
            }
            return '';
        }
    }
}
