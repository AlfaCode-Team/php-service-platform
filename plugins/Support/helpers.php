<?php

declare(strict_types=1);

use Plugins\Support\Collection;

if (!function_exists('collect')) {
    /**
     * Create a Collection from the given items.
     *
     * @param iterable<mixed,mixed> $items
     */
    function collect(iterable $items = []): Collection
    {
        return new Collection($items);
    }
}
