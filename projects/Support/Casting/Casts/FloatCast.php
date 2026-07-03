<?php

declare(strict_types=1);

namespace Project\Support\Casting\Casts;

use Project\Support\Casting\BaseCast;

final class FloatCast extends BaseCast
{
    public static function get(mixed $value, array $params = [], ?object $helper = null): float
    {
        if (! is_float($value) && ! is_int($value) && ! is_string($value)) {
            self::invalidTypeValueError($value);
        }

        return (float) $value;
    }
}
