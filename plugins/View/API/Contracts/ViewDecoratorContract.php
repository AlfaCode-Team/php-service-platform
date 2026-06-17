<?php

declare(strict_types=1);

namespace Plugins\View\API\Contracts;

/**
 * View decorators get a chance to alter rendered output before it is returned.
 * They MUST return the modified HTML. Decorators are stateless and injected
 * into the renderer at construction (never resolved from a global).
 */
interface ViewDecoratorContract
{
    public static function decorate(string $html): string;
}
