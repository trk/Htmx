<?php

namespace Totoglu\ProcessWire\Htmx\Bag;

/**
 * AttributeBag
 * 
 * Specialized ParameterBag for HTML component attributes (class, id, hx-*).
 * Provides convenience methods like addClass(), removeClass(), and can render directly to a string.
 */
class AttributeBag extends ParameterBag
{
    /**
     * Re-implement constructor to utilize the set method for proper JSON conversion when arrays are provided.
     */
    public function __construct(array $parameters = [])
    {
        foreach ($parameters as $key => $value) {
            // Automatically convert arrays to JSON at initialization phase
            $isJson = is_array($value) || is_object($value);
            $this->set($key, $value, $isJson);
        }
    }

    /**
     * Add a class or an array of classes to the "class" attribute.
     */
    public function addClass(string|array $class): void
    {
        $currentClasses = $this->getClasses();
        $newClasses = is_array($class) ? $class : explode(' ', $class);
        
        $merged = array_unique(array_filter(array_merge($currentClasses, $newClasses)));
        $this->set('class', implode(' ', $merged));
    }

    public function removeClass(string|array $class): void
    {
        $currentClasses = $this->getClasses();
        $targetClasses = is_array($class) ? $class : explode(' ', $class);
        
        $filtered = array_diff($currentClasses, $targetClasses);
        
        if (empty($filtered)) {
            $this->remove('class');
        } else {
            $this->set('class', implode(' ', $filtered));
        }
    }

    /**
     * Override set to support automated JSON conversion
     */
    public function set(string $key, mixed $value, bool $asJson = false): void
    {
        if ($asJson && (is_array($value) || is_object($value))) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        parent::set($key, $value);
    }

    /**
     * Get the current classes as an array.
     */
    public function getClasses(): array
    {
        $classString = (string)$this->get('class', '');
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
                $html[] = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
            } elseif ($value !== null && $value !== false) {
                // Standard attributes
                $html[] = sprintf(
                    '%s="%s"',
                    htmlspecialchars($key, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8')
                );
            }
        }

        return implode(' ', $html);
    }
}
