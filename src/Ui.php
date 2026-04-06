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
 */
abstract class Ui
{
    public ParameterBag $parameters;
    public AttributeBag $attributes;

    // State-Aware Integration
    public ?Component $component = null;

    // Parent-Child Relationship Properties
    public ?Ui $parent = null;

    /** @var array<int, Ui|string> */
    public array $children = [];

    public bool $isContainer = false;
    public bool $isElement = true;
    public string $name = 'ui-element';

    /** @var array<string, mixed> Default parameters automatically merged into the component */
    public array $defaultParams = [];

    /**
     * @var Htmx|null Quick access to the HTMX module API
     */
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
        // Assign HTMX API instance
        $this->htmx = wire('htmx');

        // Handle parameters
        $this->parameters = $parameters instanceof ParameterBag 
            ? $parameters 
            : new ParameterBag($parameters);

        $this->parameters->defaults($this->defaultParams);

        // Handle attributes
        $this->attributes = $attributes instanceof AttributeBag 
            ? $attributes 
            : new AttributeBag($attributes);
    }

    /**
     * Get the element's ID. 
     * If it doesn't have one, dynamically generate and assign a unique ID.
     */
    public function getId(): string
    {
        if (!$this->attributes->has('id')) {
            $uniquePath = substr(md5(uniqid('', true)), 0, 6);
            $this->attributes->set('id', "{$this->name}-{$uniquePath}");
        }
        return $this->attributes->get('id');
    }

    /**
     * Get or set a parameter fluently.
     */
    public function param(string $key, mixed $value = null): mixed
    {
        if (func_num_args() === 1) {
            return $this->parameters->get($key);
        }

        $this->parameters->set($key, $value);
        return $this;
    }

    /**
     * Helper to safely escape strings for HTML output.
     */
    protected function esc(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Set a parameter and return self for chaining.
     */
    public function setParam(string $key, mixed $value): self
    {
        $this->parameters->set($key, $value);
        return $this;
    }

    /**
     * Set a custom HTML attribute dynamically.
     */
    public function setAttribute(string $key, mixed $value, bool $asJson = false): self
    {
        $this->attributes->set($key, $value, $asJson);
        return $this;
    }

    /**
     * Check if a custom HTML attribute exists.
     */
    public function hasAttribute(string $key): bool
    {
        return $this->attributes->has($key);
    }

    /**
     * Get a custom HTML attribute.
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes->get($key, $default);
    }

    /**
     * Add a CSS class and return self for chaining.
     */
    public function addClass(string|array $class): self
    {
        $this->attributes->addClass($class);
        return $this;
    }

    /**
     * Remove a CSS class and return self for chaining.
     */
    public function removeClass(string|array $class): self
    {
        $this->attributes->removeClass($class);
        return $this;
    }

    /**
     * Helper to set custom data-* attributes.
     */
    public function data(string $key, mixed $value, bool $asJson = false): self
    {
        return $this->setAttribute("data-{$key}", $value, $asJson);
    }

    /**
     * Helper to set a raw htmx-* attribute dynamically.
     */
    public function htmx(string $key, mixed $value, bool $asJson = false): self
    {
        return $this->setAttribute("htmx-{$key}", $value, $asJson);
    }

    /**
     * Helper to set a raw hx-* attribute dynamically.
     */
    public function hx(string $key, mixed $value, bool $asJson = false): self
    {
        return $this->setAttribute("hx-{$key}", $value, $asJson);
    }

    /**
     * Helper to configure this element as an action trigger.
     * Sets: name="hx__action" value="$actionName"
     */
    public function action(string $actionName): self
    {
        return $this->setAttribute('name', 'hx__action')
            ->setAttribute('value', $actionName);
    }

    /**
     * Easily render the Stateful Component's payload if this Ui is bound to a Component.
     */
    public function renderState(): string
    {
        if ($this->component) {
            return $this->component->renderStatePayload();
        }
        return '';
    }

    /**
     * Syntactic sugar for setting Hyperscript.
     * Cleans up newlines and extra spaces automatically.
     */
    public function hyperscript(string $script): self
    {
        // Clean up multiline hyperscript strings for cleaner output
        $cleanScript = preg_replace('/\s+/', ' ', trim($script));
        return $this->setAttribute('_', $cleanScript);
    }

    /**
     * Short alias for hyperscript().
     */
    public function _(string $script): self
    {
        return $this->hyperscript($script);
    }

    /**
     * Core render method that concrete classes must implement.
     */
    abstract public function render(): string;

    /**
     * Lifecycle hook executed just before render().
     * Children can override this to prepare state/attributes.
     */
    protected function beforeRender(): void {}

    /**
     * Lifecycle hook executed immediately after render().
     * Children can override this to modify the final HTML.
     */
    protected function afterRender(string &$html): void {}

    /**
     * DOM Tree Management: Add a child component/string
     */
    public function addChild(Ui|string $child): self
    {
        if ($child instanceof Ui) {
            $child->parent = $this;
        }
        $this->children[] = $child;
        return $this;
    }

    /**
     * DOM Tree Management: Add multiple children
     */
    public function addChildren(array $children): self
    {
        foreach ($children as $child) {
            $this->addChild($child);
        }
        return $this;
    }

    /**
     * Recursively find a child by its logical name or specific parameter value.
     */
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

    /**
     * Get a specific child element by index
     */
    public function getChild(int $index): Ui|string|null
    {
        return $this->children[$index] ?? null;
    }

    /**
     * Return all children
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * Recursively finds and renders a specific child component.
     */
    public function renderChild(?string $name, ?string $paramKey = null, mixed $paramValue = null): string
    {
        $child = $this->findChild($name, $paramKey, $paramValue);
        return $child ? (string)$child : '';
    }

    /**
     * Renders all currently registered children sequentially.
     */
    public function renderChildren(): string
    {
        $html = '';
        foreach ($this->children as $child) {
            $html .= (string)$child;
        }
        return $html;
    }

    /**
     * Get the parent component if it exists
     */
    public function getParent(): ?Ui
    {
        return $this->parent;
    }

    /**
     * Traverse up the tree to find the closest parent mapping the given logical name, class name, or parameter.
     */
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

    /**
     * Total number of child components
     */
    public function count(): int
    {
        return count($this->children);
    }

    /**
     * Determines if the component should be rendered.
     * Child classes can override this to enforce required parameters or conditions.
     */
    public function renderReady(): bool
    {
        return true;
    }

    /**
     * Magic string casting implicitly calls render() securely with lifecycle hooks
     */
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
            if (wire('config')->debug) {
                return "<div class='uk-alert uk-alert-danger'><h4>Error Rendering HTMX UI</h4><p>{$e->getMessage()}</p></div>";
            }
            return '';
        }
    }
}
