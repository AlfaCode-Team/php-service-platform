<?php

declare(strict_types=1);

namespace Project\Support\Casting\Casts;

use JsonException;
use Project\Support\Casting\BaseCast;
use Project\Support\Casting\CastException;
use stdClass;

/**
 * (PHP) array|stdClass <--> string (JSON in DB column)
 *
 * Pass the "array" param ("json[array]") to decode as an associative array.
 */
final class JsonCast extends BaseCast
{
    public static function get(mixed $value, array $params = [], ?object $helper = null): array|stdClass
    {
        if (! is_string($value)) {
            self::invalidTypeValueError($value);
        }

        $associative = in_array('array', $params, true);

        try {
            return json_decode($value, $associative, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw CastException::forInvalidJsonFormat($e->getCode());
        }
    }

    public static function set(mixed $value, array $params = [], ?object $helper = null): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw CastException::forInvalidJsonFormat($e->getCode());
        }
    }
}
