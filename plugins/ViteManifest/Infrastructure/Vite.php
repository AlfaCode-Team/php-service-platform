<?php

declare(strict_types=1);

namespace Plugins\ViteManifest\Infrastructure;

use Stringable;
use Plugins\ViteManifest\ViteConfig;
use Plugins\ViteManifest\Support\Html;
use Plugins\ViteManifest\API\Contracts\ViteContract;

/**
 * Vite asset resolver — the GDA-clean rewrite of the Laravel `Vite` transplant.
 *
 * What changed vs the old class:
 *   • No framework globals. `public_path()`, `asset()`, `app()`, `collect()`,
 *     `str_random()` and the `Illuminate` `Collection`/`HtmlString` are gone —
 *     all paths + URLs come from an injected {@see ViteConfig}, HTML strings are
 *     {@see Html}, and collection ops are plain array functions.
 *   • Surfaces replace the `mode` + `admin_manifestFilename` hack: one surface =
 *     one `manifest-<surface>.json` + one `<surface>-hot` file, matching the
 *     `hkmPlugin` vite output.
 *   • Immutable configuration via `forSurface()` / `withNonce()` — no per-request
 *     mutable static state (OpenSwoole-safe; the only static is the manifest
 *     cache in {@see ManifestReader}, which is a deploy-time artifact).
 *
 * Feature-complete carry-over: HMR client injection, hashed entry resolution,
 * CSS + import preloading (modulepreload / preload), SRI integrity, CSP nonce,
 * per-tag attribute resolvers, and the React Fast Refresh preamble.
 */
final class Vite implements ViteContract, Stringable
{
    /** @var list<callable(string,string,?array,?array):array> */
    private array $scriptAttributeResolvers = [];
    /** @var list<callable(string,string,?array,?array):array> */
    private array $styleAttributeResolvers = [];
    /** @var list<callable(string,string,array,array):(array|false)> */
    private array $preloadAttributeResolvers = [];
    /** @var array<string, array> */
    private array $preloadedAssets = [];
    /** @var string|list<string> */
    private string|array $entryPoints = [];

    public function __construct(
        private ViteConfig $config,
        private readonly ManifestReader $manifest = new ManifestReader(),
    ) {
    }

    // ── configuration (immutable copies) ─────────────────────────────────────

    public function forSurface(?string $surface): static
    {
        $clone = clone $this;
        $clone->config = $this->config->withSurface($surface);
        return $clone;
    }

    public function withNonce(?string $nonce): static
    {
        $clone = clone $this;
        $clone->config = $this->config->withNonce($nonce);
        return $clone;
    }

    /** Set the default entry points used when the object is cast to a string. */
    public function withEntryPoints(string|array $entrypoints): static
    {
        $clone = clone $this;
        $clone->entryPoints = $entrypoints;
        return $clone;
    }

    /** @param callable(string,string,?array,?array):array|array $attributes */
    public function useScriptTagAttributes(callable|array $attributes): static
    {
        $this->scriptAttributeResolvers[] = is_callable($attributes) ? $attributes : static fn() => $attributes;
        return $this;
    }

    /** @param callable(string,string,?array,?array):array|array $attributes */
    public function useStyleTagAttributes(callable|array $attributes): static
    {
        $this->styleAttributeResolvers[] = is_callable($attributes) ? $attributes : static fn() => $attributes;
        return $this;
    }

    /** @param callable(string,string,array,array):(array|false)|array|false $attributes */
    public function usePreloadTagAttributes(callable|array|false $attributes): static
    {
        $this->preloadAttributeResolvers[] = is_callable($attributes) ? $attributes : static fn() => $attributes;
        return $this;
    }

    public function preloadedAssets(): array
    {
        return $this->preloadedAssets;
    }

    // ── HMR detection ────────────────────────────────────────────────────────

    public function isRunningHot(): bool
    {
        return $this->hotFile() !== null;
    }

    /**
     * The hot file whose dev-server URL should serve this surface's assets.
     *
     * Prefer THIS surface's own "{surface}-hot". But in multi-surface dev a
     * single Vite dev server runs and writes only its own "-hot" file, while it
     * still serves EVERY surface's source (src/surfaces/*). So when this
     * surface's own hot file is absent, fall back to any sibling "*-hot" so a
     * page on another surface is still served from the running dev server rather
     * than hard-failing on a missing prod manifest. An explicit hotFile override
     * never falls back. null = not hot (use the prod manifest).
     */
    private function hotFile(): ?string
    {
        $own = $this->config->hotFilePath();
        if (is_file($own)) {
            return $own;
        }
        if ($this->config->hotFile !== null) {
            return null;
        }
        $siblings = glob(rtrim($this->config->publicPath, '/') . '/*-hot') ?: [];
        return $siblings[0] ?? null;
    }

    /** Dev-server URL for an asset. The hot file holds "<devUrl><base>". */
    private function hotAsset(string $asset): string
    {
        $base = rtrim((string) @file_get_contents((string) $this->hotFile()));
        return $base . '/' . ltrim($asset, '/');
    }

    // ── rendering ────────────────────────────────────────────────────────────

    /**
     * @param string|list<string> $entrypoints
     */
    public function render(string|array $entrypoints): Stringable
    {
        $entrypoints = array_values((array) $entrypoints);

        if ($this->isRunningHot()) {
            $tags = array_map(
                fn(string $entry): string => $this->makeTagForChunk($entry, $this->hotAsset($entry), null, null),
                array_merge(['@vite/client'], $entrypoints),
            );
            return new Html(implode('', $tags));
        }

        
        $manifest = $this->manifest->load($this->config->manifestPath());
        $build    = $this->config->buildDirectory;

        $tags     = [];
        $preloads = [];

        foreach ($entrypoints as $entry) {
            $chunk = $this->manifest->chunk($manifest, $entry);

            $preloads[] = [$chunk['src'] ?? $entry, $this->assetUrl("{$build}/{$chunk['file']}"), $chunk, $manifest];

            foreach ($chunk['imports'] ?? [] as $import) {
                $importChunk = $manifest[$import] ?? null;
                if (!is_array($importChunk)) {
                    continue;
                }
                $preloads[] = [$import, $this->assetUrl("{$build}/{$importChunk['file']}"), $importChunk, $manifest];

                foreach ($importChunk['css'] ?? [] as $css) {
                    [$key, $cssChunk] = $this->findChunkByFile($manifest, $css);
                    $preloads[] = [$key, $this->assetUrl("{$build}/{$css}"), $cssChunk, $manifest];
                    $tags[]     = $this->makeTagForChunk($key, $this->assetUrl("{$build}/{$css}"), $cssChunk, $manifest);
                }
            }

            $tags[] = $this->makeTagForChunk($entry, $this->assetUrl("{$build}/{$chunk['file']}"), $chunk, $manifest);

            foreach ($chunk['css'] ?? [] as $css) {
                [$key, $cssChunk] = $this->findChunkByFile($manifest, $css);
                $preloads[] = [$key, $this->assetUrl("{$build}/{$css}"), $cssChunk, $manifest];
                $tags[]     = $this->makeTagForChunk($key, $this->assetUrl("{$build}/{$css}"), $cssChunk, $manifest);
            }
        }

        $tags = array_values(array_unique($tags));
        $stylesheets = array_filter($tags, static fn(string $t) => str_starts_with($t, '<link'));
        $scripts     = array_filter($tags, static fn(string $t) => !str_starts_with($t, '<link'));

        // Preload CSS before JS; de-dup by URL.
        $preloadTags = $this->buildPreloads($preloads);

        return new Html(implode('', $preloadTags) . implode('', $stylesheets) . implode('', $scripts));
    }

    public function asset(string $asset): string
    {
        if ($this->isRunningHot()) {
            return $this->hotAsset($asset);
        }
        $manifest = $this->manifest->load($this->config->manifestPath());
        $chunk    = $this->manifest->chunk($manifest, $asset);
        return $this->assetUrl("{$this->config->buildDirectory}/{$chunk['file']}");
    }

    public function reactRefresh(): Stringable
    {
        if (!$this->isRunningHot()) {
            return new Html('');
        }
        $attrs = $this->parseAttributes(['nonce' => $this->config->nonce ?? false]);
        $runtime = $this->hotAsset('@react-refresh');

        return new Html(sprintf(
            <<<'HTML'
            <script type="module" %s>
                import RefreshRuntime from '%s'
                RefreshRuntime.injectIntoGlobalHook(window)
                window.$RefreshReg$ = () => {}
                window.$RefreshSig$ = () => (type) => type
                window.__vite_plugin_react_preamble_installed__ = true
            </script>
            HTML,
            implode(' ', $attrs),
            $runtime,
        ));
    }

    public function manifestHash(): ?string
    {
        if ($this->isRunningHot()) {
            return null;
        }
        $path = $this->config->manifestPath();
        return is_file($path) ? (md5_file($path) ?: null) : null;
    }

    public function __toString(): string
    {
        return $this->render($this->entryPoints)->__toString();
    }

    // ── preload assembly ─────────────────────────────────────────────────────

    /**
     * @param list<array{0:string,1:string,2:array,3:array}> $preloads
     * @return list<string>
     */
    private function buildPreloads(array $preloads): array
    {
        // De-dup by URL, CSS first (higher priority).
        $unique = [];
        foreach ($preloads as $args) {
            $unique[$args[1]] = $args;
        }
        uasort($unique, fn($a, $b) => (int) $this->isCssPath($b[1]) <=> (int) $this->isCssPath($a[1]));

        $out = [];
        foreach ($unique as $args) {
            $tag = $this->makePreloadTagForChunk(...$args);
            if ($tag !== '') {
                $out[] = $tag;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{0:string,1:array}
     */
    private function findChunkByFile(array $manifest, string $file): array
    {
        foreach ($manifest as $key => $chunk) {
            if (is_array($chunk) && ($chunk['file'] ?? null) === $file) {
                return [$key, $chunk];
            }
        }
        return [$file, ['file' => $file]];
    }

    // ── tag builders ─────────────────────────────────────────────────────────

    private function makeTagForChunk(string $src, string $url, ?array $chunk, ?array $manifest): string
    {
        if (
            $this->config->nonce === null
            && $this->config->integrityKey !== false
            && !array_key_exists((string) $this->config->integrityKey, $chunk ?? [])
            && $this->scriptAttributeResolvers === []
            && $this->styleAttributeResolvers === []
        ) {
            return $this->isCssPath($url)
                ? $this->makeStylesheetTag($url, [])
                : $this->makeScriptTag($url, []);
        }

        return $this->isCssPath($url)
            ? $this->makeStylesheetTag($url, $this->resolveStyleAttributes($src, $url, $chunk, $manifest))
            : $this->makeScriptTag($url, $this->resolveScriptAttributes($src, $url, $chunk, $manifest));
    }

    private function makePreloadTagForChunk(string $src, string $url, array $chunk, array $manifest): string
    {
        $attributes = $this->resolvePreloadAttributes($src, $url, $chunk, $manifest);
        if ($attributes === false) {
            return '';
        }
        $stored = $attributes;
        unset($stored['href']);
        $this->preloadedAssets[$url] = $this->parseAttributes($stored);

        return '<link ' . implode(' ', $this->parseAttributes($attributes)) . ' />';
    }

    private function makeScriptTag(string $url, array $attributes): string
    {
        $attrs = $this->parseAttributes(array_merge([
            'type'  => 'module',
            'src'   => $url,
            'nonce' => $this->config->nonce ?? false,
        ], $attributes));
        return '<script ' . implode(' ', $attrs) . '></script>';
    }

    private function makeStylesheetTag(string $url, array $attributes): string
    {
        $attrs = $this->parseAttributes(array_merge([
            'rel'   => 'stylesheet',
            'href'  => $url,
            'nonce' => $this->config->nonce ?? false,
        ], $attributes));
        return '<link ' . implode(' ', $attrs) . ' />';
    }

    // ── attribute resolution ─────────────────────────────────────────────────

    private function resolveScriptAttributes(string $src, string $url, ?array $chunk, ?array $manifest): array
    {
        $attributes = $this->integrityAttributes($chunk);
        foreach ($this->scriptAttributeResolvers as $resolver) {
            $attributes = array_merge($attributes, $resolver($src, $url, $chunk, $manifest));
        }
        return $attributes;
    }

    private function resolveStyleAttributes(string $src, string $url, ?array $chunk, ?array $manifest): array
    {
        $attributes = $this->integrityAttributes($chunk);
        foreach ($this->styleAttributeResolvers as $resolver) {
            $attributes = array_merge($attributes, $resolver($src, $url, $chunk, $manifest));
        }
        return $attributes;
    }

    private function resolvePreloadAttributes(string $src, string $url, array $chunk, array $manifest): array|false
    {
        $attributes = $this->isCssPath($url) ? [
            'rel'         => 'preload',
            'as'          => 'style',
            'href'        => $url,
            'nonce'       => $this->config->nonce ?? false,
            'crossorigin' => $this->resolveStyleAttributes($src, $url, $chunk, $manifest)['crossorigin'] ?? false,
        ] : [
            'rel'         => 'modulepreload',
            'href'        => $url,
            'nonce'       => $this->config->nonce ?? false,
            'crossorigin' => $this->resolveScriptAttributes($src, $url, $chunk, $manifest)['crossorigin'] ?? false,
        ];

        $attributes = array_merge($attributes, $this->integrityAttributes($chunk));

        foreach ($this->preloadAttributeResolvers as $resolver) {
            $resolved = $resolver($src, $url, $chunk, $manifest);
            if ($resolved === false) {
                return false;
            }
            $attributes = array_merge($attributes, $resolved);
        }
        return $attributes;
    }

    private function integrityAttributes(?array $chunk): array
    {
        $key = $this->config->integrityKey;
        if ($key === false) {
            return [];
        }
        return ['integrity' => $chunk[$key] ?? false];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Build a public URL for a build-relative path, honouring an optional CDN base. */
    private function assetUrl(string $path): string
    {
        $base = rtrim($this->config->assetBase, '/');
        return $base . '/' . ltrim($path, '/');
    }

    private function isCssPath(string $path): bool
    {
        return preg_match('/\.(css|less|sass|scss|styl|stylus|pcss|postcss)(\?.*)?$/', $path) === 1;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return list<string>
     */
    private function parseAttributes(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $key => $value) {
            if ($value === false || $value === null) {
                continue;
            }
            if ($value === true) {
                $out[] = is_int($key) ? '' : $key;
            } elseif (is_int($key)) {
                $out[] = (string) $value;
            } else {
                $out[] = $key . '="' . $value . '"';
            }
        }
        return array_values(array_filter($out, static fn($v) => $v !== ''));
    }
}
