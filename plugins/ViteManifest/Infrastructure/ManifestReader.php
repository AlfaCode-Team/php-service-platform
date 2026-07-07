<?php

declare(strict_types=1);

namespace Plugins\ViteManifest\Infrastructure;

use Plugins\ViteManifest\Exceptions\ViteManifestNotFoundException;

/**
 * Loads and caches Vite build manifests. Replaces the old `Manifest.php`, which
 * hard-coded `public_path('dist/manifest.json')` and `app()->environment()`.
 * This reads a manifest by ABSOLUTE path (supplied by ViteConfig) and caches it
 * per-path. The cache is process-static (a manifest is a deploy-time artifact,
 * so it is safe to share across requests / OpenSwoole coroutines).
 *
 * @phpstan-type Chunk array{file: string, src?: string, isEntry?: bool, imports?: list<string>, css?: list<string>, integrity?: string}
 */
final class ManifestReader
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * Return the decoded manifest at $path, cached by path.
     *
     * @return array<string, mixed>
     * @throws ViteManifestNotFoundException when the file is missing/invalid.
     */
    public function load(string $path): array
    {
        if (isset(self::$cache[$path])) {
            return self::$cache[$path];
        }

        if (!is_file($path)) {
            throw new ViteManifestNotFoundException("Vite manifest not found at: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new ViteManifestNotFoundException("Vite manifest is not valid JSON at: {$path}");
        }

        return self::$cache[$path] = $decoded;
    }

    /**
     * Resolve a single chunk (manifest entry) by key.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function chunk(array $manifest, string $key): array
    {
        if (!isset($manifest[$key]) || !is_array($manifest[$key])) {
            throw new ViteManifestNotFoundException("Unable to locate file in Vite manifest: {$key}");
        }
        return $manifest[$key];
    }

    /** Drop the cache — tests only. */
    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
