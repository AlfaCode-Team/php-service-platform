<?php

declare(strict_types=1);

namespace Project\Support\Casting;

/**
 * A single, stateless type cast.
 *
 * Casts are pure value transformers: no I/O, no container, no globals.
 * `get()`  converts a raw DataSource value into its PHP representation.
 * `set()`  converts a PHP value into the representation a DataSource stores.
 *
 * Derived from the CodeIgniter 4 DataCaster contract, decoupled from the
 * legacy HKM Active-Record Entity for use as a dependency-free Project helper.
 */
interface CastInterface
{
    /**
     * @param mixed        $value  Data from the DataSource / DB driver
     * @param list<string> $params Additional parameters parsed from the type string
     * @param object|null  $helper Optional helper object
     */
    public static function get(mixed $value, array $params = [], ?object $helper = null): mixed;

    /**
     * @param mixed        $value  PHP native value
     * @param list<string> $params Additional parameters parsed from the type string
     * @param object|null  $helper Optional helper object
     */
    public static function set(mixed $value, array $params = [], ?object $helper = null): mixed;
}
