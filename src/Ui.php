<?php

namespace Totoglu\ProcessWire\Htmx;

use Totoglu\ProcessWire\Htmx\Bag\ParameterBag;
use Totoglu\ProcessWire\Htmx\Bag\AttributeBag;

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
    
    // Parent-Child Relationship Properties
    public ?Ui $parent = null;
    
    /** @var array<int, Ui|string> */
    public array $children = [];
    
    public bool $isContainer = false;
    public bool $isElement = true;
    public string $name = 'ui-element';
    
    /** @var array<string, mixed> Default parameters automatically merged into the component */
    public array $defaultParams = [];

    public function __construct(array $parameters = [], array $attributes = [])
    {
        // Fallback default parameters
        $parameters = array_merge($this->defaultParams, $parameters);

        $this->parameters = new ParameterBag($parameters);
        $this->attributes = new AttributeBag($attributes);
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
     * Set a parameter and return self for chaining.
     */
    public function setParam(string $key, mixed $value): self
    {
        $this->parameters->set($key, $value);
        return $this;
    }

    /**
     * Set an HTML attribute and return self for chaining.
     */
    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes->set($key, $value);
        return $this;
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
     * Syntactic sugar for setting data-* attributes.
     */
    public function data(string $key, mixed $value): self
    {
        return $this->setAttribute("data-{$key}", $value);
    }

    /**
     * Syntactic sugar for setting hx-* attributes.
     */
    public function hx(string $key, mixed $value): self
    {
        return $this->setAttribute("hx-{$key}", $value);
    }

    /**
     * Alias for hx().
     */
    public function htmx(string $key, mixed $value): self
    {
        return $this->hx($key, $value);
    }

    /**
     * Core render method that concrete classes must implement.
     */
    abstract public function render(): string;

    /**
     * Lifecycle hook executed just before render().
     * Children can override this to prepare state/attributes.
     */
    protected function beforeRender(): void
    {
    }

    /**
     * Lifecycle hook executed immediately after render().
     * Children can override this to modify the final HTML.
     */
    protected function afterRender(string &$html): void
    {
    }

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
            // __toString cannot throw exceptions in old PHP, but we convert to string
            return "<!-- Component Rendering Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . " -->";
        }
    }
}
