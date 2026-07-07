<?php

declare(strict_types=1);

namespace Project\Support\Casting;

use RuntimeException;

/**
 * Thrown for invalid cast configuration or failed transformations.
 */
final class CastException extends RuntimeException
{
    public static function forInvalidInterface(string $class): self
    {
        return new self("Invalid handler class: {$class}. Must implement " . CastInterface::class . '.');
    }

    public static function forInvalidJsonFormat(int $code): self
    {
        return new self("Invalid JSON value (json error code: {$code}).");
    }
}
