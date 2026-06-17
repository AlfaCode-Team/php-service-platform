<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot;

use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * ManifestWriter — atomically writes a compiled PHP-array manifest.
 *
 * Writes to a temp file then renames into place (atomic on POSIX), so a
 * concurrent reader never sees a half-written file. Invalidates OPcache for the
 * target afterwards so the new manifest is picked up immediately.
 */
final class ManifestWriter
{
    public static function write(string $relativePath, array $data): string
    {
        $path = Paths::cache('manifests/' . ltrim($relativePath, '/'));
        $dir  = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new BootException("Cannot create manifest directory: {$dir}");
        }

        $contents = '<?php return ' . var_export($data, true) . ';' . PHP_EOL;

        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new BootException("Failed to write manifest: {$tmp}");
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new BootException("Failed to move manifest into place: {$path}");
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        return $path;
    }
}
