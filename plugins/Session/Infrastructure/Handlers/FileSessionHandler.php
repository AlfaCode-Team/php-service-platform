<?php

declare(strict_types=1);

namespace Plugins\Session\Infrastructure\Handlers;

/**
 * File-backed session handler (GDA rewrite of the 0.3 FileSessionHandler).
 *
 * Implements PHP's native \SessionHandlerInterface so it is a drop-in for any
 * code that speaks that contract, but the Store drives it directly — PHP's
 * global session machinery is never engaged. Zero dependency: plain file I/O.
 *
 * Each session is one file named by its id under the configured directory.
 * gc() prunes files older than the configured lifetime.
 */
final class FileSessionHandler implements \SessionHandlerInterface
{
    public function __construct(
        private readonly string $path,
        private readonly int $lifetime = 7200,
    ) {
        if (!is_dir($this->path)) {
            @mkdir($this->path, 0775, true);
        }
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $file = $this->file($id);
        if (!is_file($file)) {
            return '';
        }
        // Treat an expired file as empty so a stale id starts clean.
        if (filemtime($file) + $this->lifetime < time()) {
            return '';
        }
        $contents = file_get_contents($file);
        return $contents === false ? '' : $contents;
    }

    public function write(string $id, string $data): bool
    {
        $file = $this->file($id);
        $temp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temp, $data, LOCK_EX) === false) {
            return false;
        }
        if (!rename($temp, $file)) {
            @unlink($temp);
            return false;
        }
        return true;
    }

    public function destroy(string $id): bool
    {
        $file = $this->file($id);
        return !is_file($file) || unlink($file);
    }

    public function gc(int $maxLifetime): int|false
    {
        $deleted = 0;
        foreach (glob($this->path . '/sess_*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) + $maxLifetime < time()) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    private function file(string $id): string
    {
        // id is validated by the Store before it ever reaches here.
        return $this->path . '/sess_' . $id;
    }
}
