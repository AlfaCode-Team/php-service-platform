<?php

declare(strict_types=1);

namespace Plugins\Pageflow\Http;

/**
 * The page object exchanged with the Pageflow client.
 *
 * Serialized into JSON for XHR navigations, or embedded in the initial HTML
 * document's data-page attribute on a full page load.
 *
 *   { "component": "Users/Index", "props": {...}, "url": "/users", "version": "abc" }
 */
final readonly class PageflowPage
{
    /** @param array<string,mixed> $props */
    public function __construct(
        public string $component,
        public array $props,
        public string $url,
        public string $version,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'props'     => $this->props,
            'url'       => $this->url,
            'version'   => $this->version,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Legacy boot script — the client reads window.initialPage. A layout
     * template echoes this inside <head> or before its bundle.
     */
    public function renderScript(): string
    {
        return '<script>window.initialPage = ' . $this->toJson() . ';</script>';
    }
}
