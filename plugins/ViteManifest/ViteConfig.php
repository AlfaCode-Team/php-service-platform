<?php

declare(strict_types=1);

namespace Plugins\ViteManifest;

/**
 * Immutable Vite configuration. Everything the Vite service needs is passed in
 * here — the service reads NO globals (`public_path()`, `asset()`, `app()` are
 * gone). The Provider builds one from env + Paths; `withSurface()` returns a
 * copy targeting a specific build surface (mode).
 *
 * Layout produced by the `hkmPlugin` vite plugin (tools/src/templates/frontend):
 *   {publicPath}/{buildDirectory}/manifest-{surface}.json   ← prod manifest
 *   {publicPath}/{surface}-hot                               ← dev server URL
 *   {publicPath}/{buildDirectory}/{surface}/{name}.{hash}.js ← hashed assets
 */
final class ViteConfig
{
    public function __construct(
        /** Absolute path to the web root that serves built assets (app/public). */
        public readonly string $publicPath,
        /** Build sub-directory under the public path (default "build"). */
        public readonly string $buildDirectory = 'build',
        /** Active surface/mode, or null for a single-manifest app. */
        public readonly ?string $surface = null,
        /** URL prefix for built assets — "" = same origin, or a CDN base. */
        public readonly string $assetBase = '',
        /** Manifest filename when no surface is set (vite default). */
        public readonly string $manifestFilename = 'manifest.json',
        /** Explicit hot-file path override; null = derive from publicPath+surface. */
        public readonly ?string $hotFile = null,
        /** Manifest key holding SRI hashes, or false to disable integrity. */
        public readonly string|false $integrityKey = 'integrity',
        /** CSP nonce applied to every emitted tag, or null. */
        public readonly ?string $nonce = null,
    ) {
    }

    public function withSurface(?string $surface): self
    {
        return new self(
            $this->publicPath,
            $this->buildDirectory,
            $surface,
            $this->assetBase,
            $this->manifestFilename,
            $this->hotFile,
            $this->integrityKey,
            $this->nonce,
        );
    }

    public function withNonce(?string $nonce): self
    {
        return new self(
            $this->publicPath,
            $this->buildDirectory,
            $this->surface,
            $this->assetBase,
            $this->manifestFilename,
            $this->hotFile,
            $this->integrityKey,
            $nonce,
        );
    }

    /** Absolute path to the "hot" file for the active surface. */
    public function hotFilePath(): string
    {
        if ($this->hotFile !== null) {
            return $this->hotFile;
        }
        $prefix = $this->surface !== null && $this->surface !== '' ? $this->surface . '-' : '';
        return $this->publicPath . '/' . $prefix . 'hot';
    }

    /** Absolute path to the production manifest for the active surface. */
    public function manifestPath(): string
    {
        $name = $this->surface !== null && $this->surface !== ''
            ? "manifest-{$this->surface}.json"
            : $this->manifestFilename;

        return $this->publicPath . '/' . $this->buildDirectory . '/' . $name;
    }
}
