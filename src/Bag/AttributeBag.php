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
     */
    public function __construct(array $parameters = [])
    {
        // Call parent with empty array, process parameters manually to handle JSON conversion
        parent::__construct([]);
        $this->add($parameters);
    }

    /**
     * Adds parameters, ensuring array/object values are converted to JSON.
     */
    public function add(array|ParameterBag $parameters = []): self
    {
        $parsed = $parameters instanceof ParameterBag ? $parameters->all() : $parameters;
        foreach ($parsed as $key => $value) {
            $isJson = is_array($value) || is_object($value);
            $this->set((string)$key, $value, $isJson);
        }
        return $this;
    }

    /**
     * Replaces parameters, resetting all attributes first.
     */
    public function replace(array|ParameterBag $parameters = []): self
    {
        $this->parameters = [];
        return $this->add($parameters);
    }

    /**
     * Add a class or an array of classes to the "class" attribute.
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
     * Get the current classes as an array.
     */
    public function getClasses(): array
    {
        $classString = $this->getString('class');
        return array_filter(explode(' ', trim($classString)));
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
        $html = [];
        foreach ($this->all() as $key => $value) {
            if ($value === true) {
                // Boolean attributes (e.g., disabled, readonly)
                $html[] = htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8');
            } elseif ($value !== null && $value !== false) {
                // Standard attributes
                $html[] = sprintf(
                    '%s="%s"',
                    htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8')
                );
            }
        }

        return implode(' ', $html);
    }
}
