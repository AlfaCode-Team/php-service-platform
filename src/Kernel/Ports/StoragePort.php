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

    /**
     * Stream a blob to storage without buffering it in memory — for large
     * uploads/exports. $resource is a readable stream consumed to EOF.
     *
     * @param resource $resource
     * @return string the stored path
     */
    public function storeStream($resource, string $filename, string $path = '', string $visibility = 'private'): string;

    public function get(string $path): string;

    /**
     * Open a stored blob as a readable stream — for large downloads that must
     * not be loaded fully into memory. Caller owns closing the returned handle.
     *
     * @return resource
     */
    public function readStream(string $path);

    public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string;

    public function exists(string $path): bool;

    public function delete(string $path): bool;
}
