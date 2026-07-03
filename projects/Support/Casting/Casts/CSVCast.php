<?php

declare(strict_types=1);

namespace Project\Support\Casting\Casts;

use Project\Support\Casting\BaseCast;

/**
 * (PHP) array <--> comma-separated string (DB column)
 */
final class CSVCast extends BaseCast
{
    public static function get(mixed $value, array $params = [], ?object $helper = null): array
    {
        if (! is_string($value)) {
            self::invalidTypeValueError($value);
        }

        return $value === '' ? [] : explode(',', $value);
    }

    public static function set(mixed $value, array $params = [], ?object $helper = null): string
    {
        if (! is_array($value)) {
            self::invalidTypeValueError($value);
        }

        return implode(',', $value);
    }
}
