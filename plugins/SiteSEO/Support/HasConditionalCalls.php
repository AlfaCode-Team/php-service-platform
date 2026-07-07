<?php

declare(strict_types=1);

namespace Plugins\SiteSEO\Support;

/**
 * Adds fluent conditional method chaining to a builder.
 *
 * `$this->when($cond)->method(...)` executes `method` only when `$cond` is
 * truthy; otherwise a no-op proxy is returned so the chain continues without
 * mutating the object. `unless()` is the inverse.
 */
trait HasConditionalCalls
{
    /**
     * Return the object itself when the condition holds, otherwise a no-op
     * proxy that swallows the next method call and returns the object.
     *
     * @return $this|ConditionalCallProxy
     */
    public function when(mixed $condition): mixed
    {
        return $condition ? $this : new ConditionalCallProxy($this);
    }

    /**
     * Inverse of {@see when()}.
     *
     * @return $this|ConditionalCallProxy
     */
    public function unless(mixed $condition): mixed
    {
        return $condition ? new ConditionalCallProxy($this) : $this;
    }
}
