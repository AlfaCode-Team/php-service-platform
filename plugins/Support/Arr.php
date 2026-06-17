<?php

declare(strict_types=1);

namespace Plugins\Support;

/**
 * Array utility helpers used by Collection and available standalone.
 * Original implementation — no framework dependency.
 */
final class Arr
{
    /** @param array<mixed> $array */
    public static function get(array $array, string|int|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $array;
        }
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }
        if (!str_contains((string) $key, '.')) {
            return $default;
        }
        foreach (explode('.', (string) $key) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }
        return $array;
    }

    /** @param array<mixed> $array */
    public static function set(array &$array, string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref =& $array;
        while (count($segments) > 1) {
            $segment = array_shift($segments);
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref =& $ref[$segment];
        }
        $ref[array_shift($segments)] = $value;
    }

    /** @param array<mixed> $array */
    public static function has(array $array, string $key): bool
    {
        return self::get($array, $key, $sentinel = "\0__missing__\0") !== $sentinel;
    }

    /** @param array<mixed> $array @return array<mixed> */
    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /** @param array<mixed> $array @return array<mixed> */
    public static function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    /** @param array<mixed> $array */
    public static function first(array $array, ?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $array === [] ? $default : reset($array);
        }
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }
        return $default;
    }

    /** @param array<mixed> $array @return array<mixed> */
    public static function flatten(array $array, int $depth = PHP_INT_MAX): array
    {
        $result = [];
        foreach ($array as $item) {
            if (!is_array($item) || $depth < 1) {
                $result[] = $item;
            } else {
                foreach (self::flatten($item, $depth - 1) as $sub) {
                    $result[] = $sub;
                }
            }
        }
        return $result;
    }

    /** @param array<mixed> $array @return array<mixed> */
    public static function pluck(array $array, string $value, ?string $key = null): array
    {
        $results = [];
        foreach ($array as $item) {
            $itemValue = is_array($item) ? self::get($item, $value) : (is_object($item) ? ($item->{$value} ?? null) : null);
            if ($key === null) {
                $results[] = $itemValue;
            } else {
                $itemKey = is_array($item) ? self::get($item, $key) : ($item->{$key} ?? null);
                $results[$itemKey] = $itemValue;
            }
        }
        return $results;
    }

    public static function isAssoc(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }
}
