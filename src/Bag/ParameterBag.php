<?php

declare(strict_types=1);

namespace Totoglu\Htmx\Bag;

/**
 * ParameterBag
 * 
 * An Object-Oriented wrapper for an array of parameters.
 */
class ParameterBag implements \Countable, \IteratorAggregate
{
    protected array $parameters;

    public function __construct(array $parameters = [])
    {
        $this->parameters = $parameters;
    }

    public function all(): array
    {
        return $this->parameters;
    }

    public function toArray(): array
    {
        return $this->parameters;
    }

    public function keys(): array
    {
        return array_keys($this->parameters);
    }

    public function count(): int
    {
        return count($this->parameters);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->parameters);
    }

    public function replace(array|ParameterBag $parameters = []): self
    {
        $this->parameters = $parameters instanceof self ? $parameters->all() : $parameters;
        return $this;
    }

    /**
     * Adds parameters to the bag (overwriting existing keys).
     */
    public function add(array|ParameterBag $parameters = []): self
    {
        $parsed = $parameters instanceof self ? $parameters->all() : $parameters;
        $this->parameters = array_replace($this->parameters, $parsed);
        return $this;
    }

    /**
     * Merges parameters to the bag (alias for add).
     */
    public function merge(array|ParameterBag $parameters = []): self
    {
        return $this->add($parameters);
    }

    /**
     * Set a default value ONLY if the key does not exist.
     */
    public function default(string $key, mixed $value): self
    {
        if (!$this->has($key)) {
            $this->set($key, $value);
        }
        return $this;
    }

    /**
     * Set multiple default values efficiently.
     */
    public function defaults(array $defaults): self
    {
        $this->parameters = array_replace($defaults, $this->parameters);
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->parameters) ? $this->parameters[$key] : $default;
    }

    public function set(string $key, mixed $value): self
    {
        $this->parameters[$key] = $value;
        return $this;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->parameters);
    }

    public function remove(string $key): self
    {
        unset($this->parameters[$key]);
        return $this;
    }

    /**
     * Gets a parameter and removes it from the bag.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);
        return $value;
    }

    // --- Type-safe Getters ---

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function getFloat(string $key, float $default = 0.0): float
    {
        $value = $this->get($key, $default);
        return is_numeric($value) ? (float) $value : $default;
    }

    public function getArray(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);
        return is_array($value) ? $value : (is_null($value) ? $default : [$value]);
    }
}
