<?php

declare(strict_types=1);

namespace Project\Support\Casting\Casts;

use Project\Support\Casting\BaseCast;

final class BooleanCast extends BaseCast
{
    public static function get(mixed $value, array $params = [], ?object $helper = null): bool
    {
        // PostgreSQL boolean literals
        if ($value === 't') {
            return true;
        }
        if ($value === 'f') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
