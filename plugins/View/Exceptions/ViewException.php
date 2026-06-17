<?php

declare(strict_types=1);

namespace Plugins\View\Exceptions;

use Plugins\View\API\Contracts\ViewDecoratorContract;
use RuntimeException;

/**
 * View rendering errors. Self-contained — no language helper / global lookups
 * (the original CodeIgniter version called lang(); this framework injects
 * everything, so messages are inlined).
 */
final class ViewException extends RuntimeException
{
    public static function forInvalidFile(string $file): self
    {
        return new self(sprintf('View file "%s" could not be located.', $file));
    }

    public static function forInvalidDecorator(string $className): self
    {
        return new self(sprintf(
            '"%s" is not a valid view decorator: it must implement %s.',
            $className,
            ViewDecoratorContract::class,
        ));
    }
}
