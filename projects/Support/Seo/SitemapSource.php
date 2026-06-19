<?php

declare(strict_types=1);

namespace Project\Support\Seo;

/**
 * The complete, lazy URL stream for a site's sitemap.
 *
 * Combines the two halves of a real sitemap into ONE iterable you can hand
 * straight to {@see SitemapStreamWriter}:
 *
 *   1. STATIC pages — public, parameter-free GET routes from {@see RouteCatalog}
 *      (`/`, `/about`, `/contact`, …).
 *   2. DYNAMIC pages — every {@see SitemapUrlProvider}'s database-backed URLs
 *      (`/blog/{slug}` → `/blog/hello`, `/blog/world`, …).
 *
 * Everything is yielded lazily: the static list is small, and each provider is a
 * generator over a DB cursor, so the whole stream is O(1) memory no matter how
 * many rows back it.
 *
 * It also answers the question "did I forget a sitemap for one of my dynamic
 * routes?" — {@see uncoveredDynamicRoutes()} lists every `{param}` route in the
 * manifest that NO provider claims, so a `/news/{id}` route can't silently fall
 * out of the sitemap.
 */
final class SitemapSource
{
    /** @var list<SitemapUrlProvider> */
    private array $providers;

    /**
     * @param iterable<SitemapUrlProvider> $providers
     */
    public function __construct(
        private readonly RouteCatalog $catalog,
        iterable $providers = [],
    ) {
        $this->providers = is_array($providers) ? array_values($providers) : iterator_to_array($providers, false);
    }

    /**
     * The full lazy stream: static public pages first, then each provider's URLs.
     *
     * @return \Generator<int, array<string, string>|string>
     */
    public function all(): \Generator
    {
        foreach ($this->catalog->publicPaths() as $path) {
            yield ['loc' => $path, 'changefreq' => 'weekly', 'priority' => '0.8'];
        }

        foreach ($this->providers as $provider) {
            yield from $provider->urls();
        }
    }

    /**
     * Only the dynamic (provider-backed) URLs — handy when static pages live in a
     * separate sitemap.
     *
     * @return \Generator<int, array<string, string>|string>
     */
    public function dynamic(): \Generator
    {
        foreach ($this->providers as $provider) {
            yield from $provider->urls();
        }
    }

    /**
     * The `{param}` route patterns found in the manifest (e.g. "/blog/{slug}").
     *
     * @return list<string>
     */
    public function dynamicRoutes(): array
    {
        $found = [];

        foreach (array_keys($this->catalog->all()) as $key) {
            [$method, $path] = array_pad(explode(' ', $key, 2), 2, '');

            if (strtoupper($method) === 'GET' && str_contains($path, '{')) {
                $found[$path] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * Dynamic routes that have NO provider — these are MISSING from the sitemap.
     *
     * Compares each `{param}` GET route against the providers' patterns. Use it in
     * a health check / test so adding `/news/{id}` without a NewsSitemapProvider
     * fails loudly instead of quietly omitting every news page.
     *
     * @return list<string>
     */
    public function uncoveredDynamicRoutes(): array
    {
        $covered = array_map(static fn(SitemapUrlProvider $p): string => $p->pattern(), $this->providers);

        return array_values(array_filter(
            $this->dynamicRoutes(),
            static fn(string $pattern): bool => !in_array($pattern, $covered, true),
        ));
    }

    /** @return list<string> the patterns claimed by registered providers */
    public function coveredPatterns(): array
    {
        return array_map(static fn(SitemapUrlProvider $p): string => $p->pattern(), $this->providers);
    }
}
