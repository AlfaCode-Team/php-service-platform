<?php

declare(strict_types=1);

namespace Project\Support\Seo;

use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * Reads the compiled route manifest and exposes the project's PUBLIC pages —
 * the routes that belong in a sitemap.
 *
 * The kernel compiles every plugin + project route into
 * `var/cache/manifests/route-manifest.php` (see CompileRouteManifestStage). That
 * file is the single source of truth for "what URLs does this site answer", so
 * the sitemap is derived from it rather than maintained by hand — add a route,
 * it shows up in the sitemap automatically.
 *
 * Each manifest entry is keyed by `"METHOD path"` and carries:
 *   handler, module, solves, filters[], requires[], overrides
 *
 * A route is considered a public, indexable page when ALL of these hold:
 *   - method is GET                         (only fetchable pages)
 *   - the path is STATIC (no `{param}`)     (a sitemap needs concrete URLs)
 *   - it is not auth-gated                  (no `auth` filter)
 *   - it is not an API/asset/SEO endpoint   (excluded path prefixes)
 *
 * Dynamic routes (`/blog/{slug}`) cannot be enumerated from the manifest — feed
 * those URLs in yourself via {@see SitemapGenerator::add()} from your data store.
 */
final class RouteCatalog
{
    /** Path prefixes never included in a sitemap. */
    private const DEFAULT_EXCLUDED_PREFIXES = ['/api', '/_', '/assets', '/storage'];

    /** Exact paths never included (the SEO endpoints themselves). */
    private const DEFAULT_EXCLUDED_PATHS = ['/robots.txt', '/sitemap.xml', '/sitemaps_xsl.xsl'];

    /**
     * @param array<string, array<string, mixed>> $manifest keyed by "METHOD path"
     */
    public function __construct(private readonly array $manifest)
    {
    }

    /**
     * Build from the compiled manifest on disk (resolves under the active project).
     */
    public static function fromManifest(?string $manifestPath = null): self
    {
        $path = $manifestPath ?? Paths::cache('manifests/route-manifest.php');

        /** @var array<string, array<string, mixed>> $manifest */
        $manifest = is_file($path) ? (require $path) : [];

        return new self(is_array($manifest) ? $manifest : []);
    }

    /**
     * Public, static GET paths suitable for a sitemap (leading-slash paths).
     *
     * @param list<string> $excludePrefixes Extra path prefixes to skip.
     * @param list<string> $excludePaths    Extra exact paths to skip.
     * @return list<string>
     */
    public function publicPaths(array $excludePrefixes = [], array $excludePaths = []): array
    {
        $prefixes = [...self::DEFAULT_EXCLUDED_PREFIXES, ...$excludePrefixes];
        $paths    = [...self::DEFAULT_EXCLUDED_PATHS, ...$excludePaths];

        $found = [];

        foreach ($this->manifest as $key => $entry) {
            [$method, $path] = array_pad(explode(' ', $key, 2), 2, '');

            if (strtoupper($method) !== 'GET') {
                continue;
            }
            if ($path === '' || str_contains($path, '{')) {
                continue;   // dynamic — cannot enumerate from the manifest
            }
            if ($this->isAuthGated($entry)) {
                continue;
            }
            if (in_array($path, $paths, true)) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    continue 2;
                }
            }

            $found[$path] = true;   // dedupe (project may override a plugin route)
        }

        return array_keys($found);
    }

    /**
     * Every raw manifest entry, keyed by "METHOD path" — for custom filtering.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->manifest;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function isAuthGated(array $entry): bool
    {
        $filters = $entry['filters'] ?? [];

        if (!is_array($filters)) {
            return false;
        }

        foreach ($filters as $filter) {
            // filters are specs like "auth" or "throttle:60,1"
            $alias = is_string($filter) ? explode(':', $filter, 2)[0] : '';
            if ($alias === 'auth') {
                return true;
            }
        }

        return false;
    }
}
