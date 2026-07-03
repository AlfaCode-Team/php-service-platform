<?php

declare(strict_types=1);

namespace Project\Support\Casting\Casts;

use Project\Support\Casting\BaseCast;

final class IntegerCast extends BaseCast
{
    public static function get(mixed $value, array $params = [], ?object $helper = null): int
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
            self::invalidTypeValueError($value);
        }

        return (int) $value;
    }
}
