<?php

declare(strict_types=1);

namespace Project\Support\Casting\Casts;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Project\Support\Casting\BaseCast;

/**
 * (PHP) DateTimeImmutable <--> unix timestamp int (DB column)
 */
final class TimestampCast extends BaseCast
{
    public static function get(mixed $value, array $params = [], ?object $helper = null): DateTimeImmutable
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (new DateTimeImmutable())->setTimestamp((int) $value);
        }

        if (is_string($value)) {
            try {
                return new DateTimeImmutable($value);
            } catch (\Exception $e) {
                throw new InvalidArgumentException("Unparsable timestamp value: {$value}", 0, $e);
            }
        }

        self::invalidTypeValueError($value);
    }

    public static function set(mixed $value, array $params = [], ?object $helper = null): int
    {
        if (! $value instanceof DateTimeInterface) {
            self::invalidTypeValueError($value);
        }

        return $value->getTimestamp();
    }
}
