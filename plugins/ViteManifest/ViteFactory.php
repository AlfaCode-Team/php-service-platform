<?php

declare(strict_types=1);

namespace Plugins\ViteManifest;

use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use Plugins\ViteManifest\Infrastructure\Vite;
use Plugins\ViteManifest\Infrastructure\ManifestReader;

/**
 * Builds a {@see Vite} from environment + Paths — the single place that reads
 * config, shared by the Provider (container binding) and the view helpers.
 *
 * All keys are optional and match module.json `config[]`:
 *   VITE_PUBLIC_PATH      absolute web root (default: <project>/app/public)
 *   VITE_BUILD_DIRECTORY  build sub-dir (default "build")
 *   VITE_SURFACE          default surface/mode (default: none)
 *   VITE_MANIFEST         manifest filename when no surface (default manifest.json)
 *   VITE_HOT_PATH         explicit hot-file path override
 *   ASSET_URL             CDN/base URL prefix for built assets (default same origin)
 *   VITE_INTEGRITY_KEY    manifest SRI key, or "false"/"" to disable (default "integrity")
 *   VITE_NONCE            CSP nonce applied to every tag
 */
final class ViteFactory
{
    public static function fromEnv(): Vite
    {
        return new Vite(self::configFromEnv(), new ManifestReader());
    }

    public static function configFromEnv(): ViteConfig
    {
        $publicPath = (string) (env('VITE_PUBLIC_PATH') ?: Paths::project('app/public'));

        // Default SRI key "integrity"; set VITE_INTEGRITY_KEY=false to disable.
        $integrity = env('VITE_INTEGRITY_KEY');
        $integrityKey = match (true) {
            $integrity === 'false'                        => false,
            is_string($integrity) && $integrity !== ''    => $integrity,
            default                                       => 'integrity',
        };

        return new ViteConfig(
            publicPath:       rtrim($publicPath, '/'),
            buildDirectory:   trim((string) (env('VITE_BUILD_DIRECTORY') ?: 'build'), '/'),
            surface:          (($s = env('VITE_SURFACE')) !== null && $s !== '') ? (string) $s : null,
            assetBase:        (string) (env('ASSET_URL') ?: ''),
            manifestFilename: (string) (env('VITE_MANIFEST') ?: 'manifest.json'),
            hotFile:          (($h = env('VITE_HOT_PATH')) !== null && $h !== '') ? (string) $h : null,
            integrityKey:     $integrityKey,
            nonce:            (($n = env('VITE_NONCE')) !== null && $n !== '') ? (string) $n : null,
        );
    }
}
