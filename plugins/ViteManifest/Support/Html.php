<?php

declare(strict_types=1);

namespace Plugins\ViteManifest\Support;

use Stringable;

/**
 * A tiny value object marking a string as already-safe HTML. Replaces the
 * Laravel `HtmlString`/`Htmlable` coupling the old class carried — the plugin
 * ships its own so it depends on nothing outside the kernel.
 *
 * Echo it directly in a view (`<?= vite(...) ?>`) or cast with `(string)`.
 */
final class Html implements Stringable
{
    public function __construct(private readonly string $html)
    {
    }

    public function toHtml(): string
    {
        return $this->html;
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
