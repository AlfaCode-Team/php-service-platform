<?php

declare(strict_types=1);

namespace Plugins\Support;

/**
 * String utility helpers. Original implementation — no framework dependency.
 */
final class Str
{
    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    public static function camel(string $value): string
    {
        return lcfirst(self::studly($value));
    }

    public static function snake(string $value, string $delimiter = '_'): string
    {
        if (ctype_lower($value)) {
            return $value;
        }
        $value = preg_replace('/\s+/u', '', ucwords($value)) ?? $value;
        $value = preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value) ?? $value;
        return strtolower($value);
    }

    public static function kebab(string $value): string
    {
        return self::snake($value, '-');
    }

    public static function slug(string $value, string $separator = '-'): string
    {
        $value = preg_replace('/[^\pL\pN]+/u', $separator, $value) ?? $value;
        return trim(strtolower($value), $separator);
    }

    public static function startsWith(string $haystack, string $needle): bool
    {
        return $needle !== '' && str_starts_with($haystack, $needle);
    }

    public static function endsWith(string $haystack, string $needle): bool
    {
        return $needle !== '' && str_ends_with($haystack, $needle);
    }

    public static function contains(string $haystack, string $needle): bool
    {
        return $needle !== '' && str_contains($haystack, $needle);
    }

    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        return mb_strlen($value) <= $limit ? $value : rtrim(mb_substr($value, 0, $limit)) . $end;
    }

    public static function random(int $length = 16): string
    {
        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }
}
