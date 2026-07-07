<?php

declare(strict_types=1);

namespace Plugins\SiteSEO\Support;

/**
 * No-op proxy returned by {@see HasConditionalCalls::when()} when a condition
 * fails. Any method call on it is swallowed and the original target is returned
 * so the fluent chain can continue uninterrupted.
 *
 * @internal
 */
final class ConditionalCallProxy
{
    public function __construct(private readonly object $target)
    {
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): object
    {
        return $this->target;
    }
}
