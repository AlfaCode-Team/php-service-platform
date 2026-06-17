<?php

declare(strict_types=1);

namespace Plugins\Support;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Fluent, immutable-friendly collection wrapper around a PHP array.
 *
 * Original implementation written for this framework — no Laravel dependency.
 * Most transforming methods return a NEW Collection so chains stay side-effect
 * free; mutating helpers (push/put) return $this by design.
 *
 * @template TKey of array-key
 * @template TValue
 * @implements ArrayAccess<TKey,TValue>
 * @implements IteratorAggregate<TKey,TValue>
 */
final class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /** @var array<TKey,TValue> */
    private array $items;

    /** @param iterable<TKey,TValue> $items */
    public function __construct(iterable $items = [])
    {
        $this->items = $items instanceof Traversable ? iterator_to_array($items) : $items;
    }

    /** @param iterable<mixed,mixed> $items */
    public static function make(iterable $items = []): self
    {
        return new self($items);
    }

    /** @return array<TKey,TValue> */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    public function map(callable $callback): self
    {
        $keys = array_keys($this->items);
        $mapped = array_map($callback, $this->items, $keys);
        return new self(array_combine($keys, $mapped));
    }

    public function filter(?callable $callback = null): self
    {
        return new self($callback === null
            ? array_filter($this->items)
            : array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    public function reject(callable $callback): self
    {
        return $this->filter(static fn($v, $k) => !$callback($v, $k));
    }

    public function each(callable $callback): self
    {
        foreach ($this->items as $key => $value) {
            if ($callback($value, $key) === false) {
                break;
            }
        }
        return $this;
    }

    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce(array_keys($this->items), fn($carry, $key) => $callback($carry, $this->items[$key], $key), $initial);
    }

    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        return Arr::first($this->items, $callback, $default);
    }

    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $this->items === [] ? $default : end($this->items);
        }
        return Arr::first(array_reverse($this->items, true), $callback, $default);
    }

    public function pluck(string $value, ?string $key = null): self
    {
        return new self(Arr::pluck($this->items, $value, $key));
    }

    public function keys(): self
    {
        return new self(array_keys($this->items));
    }

    public function values(): self
    {
        return new self(array_values($this->items));
    }

    public function push(mixed $value): self
    {
        $this->items[] = $value;
        return $this;
    }

    public function put(string|int $key, mixed $value): self
    {
        $this->items[$key] = $value;
        return $this;
    }

    public function get(string|int $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    public function has(string|int $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function contains(mixed $value): bool
    {
        if (is_callable($value)) {
            return $this->first($value, $sentinel = "\0__none__\0") !== $sentinel;
        }
        return in_array($value, $this->items, true);
    }

    public function merge(iterable $items): self
    {
        $other = $items instanceof Traversable ? iterator_to_array($items) : $items;
        return new self(array_merge($this->items, $other));
    }

    public function unique(): self
    {
        return new self(array_values(array_unique($this->items, SORT_REGULAR)));
    }

    public function reverse(): self
    {
        return new self(array_reverse($this->items, true));
    }

    public function slice(int $offset, ?int $length = null): self
    {
        return new self(array_slice($this->items, $offset, $length, true));
    }

    public function take(int $limit): self
    {
        return $limit < 0 ? $this->slice($limit) : $this->slice(0, $limit);
    }

    public function chunk(int $size): self
    {
        if ($size < 1) {
            return new self();
        }
        $chunks = [];
        foreach (array_chunk($this->items, $size, true) as $chunk) {
            $chunks[] = new self($chunk);
        }
        return new self($chunks);
    }

    public function sort(?callable $callback = null): self
    {
        $items = $this->items;
        $callback ? uasort($items, $callback) : asort($items);
        return new self($items);
    }

    public function sortBy(string $key): self
    {
        return $this->sort(static fn($a, $b) => (Arr::get((array) $a, $key) <=> Arr::get((array) $b, $key)));
    }

    public function groupBy(string|callable $key): self
    {
        $groups = [];
        foreach ($this->items as $item) {
            $groupKey = is_callable($key) ? $key($item) : Arr::get((array) $item, $key);
            $groups[$groupKey][] = $item;
        }
        return (new self($groups))->map(static fn(array $g) => new self($g));
    }

    public function where(string $key, mixed $value): self
    {
        return $this->filter(static fn($item) => Arr::get((array) $item, $key) === $value);
    }

    public function sum(string|callable|null $key = null): int|float
    {
        $values = $key === null
            ? $this->items
            : array_map(is_callable($key) ? $key : static fn($i) => Arr::get((array) $i, $key), $this->items);
        return array_sum($values);
    }

    public function avg(string|callable|null $key = null): int|float|null
    {
        return $this->count() === 0 ? null : $this->sum($key) / $this->count();
    }

    public function min(string|callable|null $key = null): mixed
    {
        $values = $this->extract($key);
        return $values === [] ? null : min($values);
    }

    public function max(string|callable|null $key = null): mixed
    {
        $values = $this->extract($key);
        return $values === [] ? null : max($values);
    }

    public function implode(string $glue, ?string $key = null): string
    {
        $values = $key === null ? $this->items : Arr::pluck($this->items, $key);
        return implode($glue, $values);
    }

    public function flatten(int $depth = PHP_INT_MAX): self
    {
        return new self(Arr::flatten($this->items, $depth));
    }

    public function pipe(callable $callback): mixed
    {
        return $callback($this);
    }

    /** @return array<TKey,TValue> */
    public function toArray(): array
    {
        return array_map(
            static fn($v) => $v instanceof self ? $v->toArray() : $v,
            $this->items,
        );
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->jsonSerialize(), $flags | JSON_THROW_ON_ERROR);
    }

    /** @return array<mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    /** @return array<mixed> */
    private function extract(string|callable|null $key): array
    {
        if ($key === null) {
            return $this->items;
        }
        return array_map(is_callable($key) ? $key : static fn($i) => Arr::get((array) $i, $key), $this->items);
    }
}
