<?php

declare(strict_types=1);

namespace Project\Support\Casting\Casts;

use Project\Support\Casting\BaseCast;

final class ObjectCast extends BaseCast
{
    public static function get(mixed $value, array $params = [], ?object $helper = null): object
    {
        return (object) $value;
    }
}
