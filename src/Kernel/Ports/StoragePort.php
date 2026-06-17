<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * StoragePort — the ONLY way modules read/write blobs.
 * The kernel defines this interface; the project provides the adapter.
 */
interface StoragePort
{
    /** @return string the stored path */
    public function store(string $contents, string $filename, string $path = '', string $visibility = 'private'): string;

    public function get(string $path): string;

    public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string;

    public function exists(string $path): bool;

    public function delete(string $path): bool;
}
