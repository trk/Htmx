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

    public function __construct(array $parameters = [], array $attributes = [])
    {
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
     * Core render method that concrete classes must implement.
     */
    abstract public function render(): string;

    /**
     * Magic string casting implicitly calls render()
     */
    public function __toString(): string
    {
        try {
            return $this->render();
        } catch (\Throwable $e) {
            // __toString cannot throw exceptions in old PHP, but we convert to string
            return "<!-- Component Rendering Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . " -->";
        }
    }
}
