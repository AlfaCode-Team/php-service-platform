<?php

declare(strict_types=1);

namespace Project\Support\Casting\Casts;

use Project\Support\Casting\BaseCast;

/**
 * (PHP) array <--> serialized string (DB column)
 *
 * Uses native PHP serialization (the legacy WP `maybe_serialize` helper is
 * not part of the framework). Values already stored as a PHP-serialized
 * string ("a:" / "s:") are transparently unserialized on read.
 */
final class ArrayCast extends BaseCast
{
    public static function get(mixed $value, array $params = [], ?object $helper = null): array
    {
        if (! is_string($value)) {
            self::invalidTypeValueError($value);
        }

        if (str_starts_with($value, 'a:') || str_starts_with($value, 's:')) {
            $decoded = @unserialize($value, ['allowed_classes' => false]);
            if ($decoded !== false || $value === 'b:0;') {
                return (array) $decoded;
            }
        }

        return (array) $value;
    }

    public static function set(mixed $value, array $params = [], ?object $helper = null): string
    {
        return serialize($value);
    }
}
