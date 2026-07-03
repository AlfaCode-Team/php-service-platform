<?php

declare(strict_types=1);

namespace Project\Support\Casting;

use InvalidArgumentException;

/**
 * Identity cast. Subclasses override only the direction they transform.
 */
abstract class BaseCast implements CastInterface
{
    public static function get(mixed $value, array $params = [], ?object $helper = null): mixed
    {
        return $value;
    }

    public static function set(mixed $value, array $params = [], ?object $helper = null): mixed
    {
        return $value;
    }

    protected static function invalidTypeValueError(mixed $value): never
    {
        $message = '[' . static::class . '] Invalid value type: ' . get_debug_type($value);
        if (is_scalar($value)) {
            $message .= ', and its value: ' . var_export($value, true);
        }

        throw new InvalidArgumentException($message);
    }
}
