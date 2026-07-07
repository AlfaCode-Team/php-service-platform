<?php

declare(strict_types=1);

namespace Plugins\ViteManifest\API\Contracts;

use Stringable;

/**
 * Published surface of the ViteManifest plugin. Inject this into a controller,
 * or use the `vite()` / `vite_asset()` / `vite_react_refresh()` helpers inside
 * an (isolated-scope) view.
 *
 * In DEV (a `<surface>-hot` file exists) it points tags at the running Vite dev
 * server; in PROD it reads `manifest-<surface>.json` and emits hashed,
 * preload-optimised tags.
 */
interface ViteContract
{
    /**
     * Render <script>/<link>/preload tags for one or more entry points.
     *
     * @param string|list<string> $entrypoints manifest keys, e.g. "src/surfaces/admin/index.tsx"
     */
    public function render(string|array $entrypoints): Stringable;

    /** Resolve the public URL of a single asset (hashed in prod, dev URL in HMR). */
    public function asset(string $asset): string;

    /** The React Fast Refresh preamble (dev only; empty string in prod). */
    public function reactRefresh(): Stringable;

    /** True when the Vite dev server is running for the active surface. */
    public function isRunningHot(): bool;

    /** A copy of this service bound to a specific build surface (mode). */
    public function forSurface(?string $surface): static;

    /** A copy of this service using the given CSP nonce on every tag. */
    public function withNonce(?string $nonce): static;

    /** A stable hash of the active manifest for cache-busting, or null in dev. */
    public function manifestHash(): ?string;
}
