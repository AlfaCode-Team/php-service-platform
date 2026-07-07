<?php

declare(strict_types=1);

namespace Project\Http\Controllers\Concerns;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\UploadedFile;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort;

/**
 * Storage helpers for base controllers.
 *
 * Wraps the request-scoped StoragePort so a controller can read/write blobs
 * without reaching into the container by hand. Base controllers implement
 * RequestAware, so ExecuteStage calls setRequest() with the active Request (the
 * one carrying the request-scoped container) BEFORE the action runs; these
 * helpers therefore need no $request argument:
 *
 *   public function upload(): Response   // RequestAware — no $request param
 *   {
 *       $path = $this->storeUpload('file', 'uploads');
 *       return $this->created(['path' => $path, 'url' => $this->fileUrl($path, 600)]);
 *   }
 *
 * Storage is an ON-DEMAND plugin (solves "storage.local"), so a route that uses
 * these helpers MUST declare it, otherwise StoragePort is unbound:
 *   { "requires": ["storage.local"] }      // proj.json / module.json
 *
 * When the plugin is absent the read helpers return null / false and the write
 * helpers throw a clear RuntimeException (a missing backing store is a real
 * error, not something to silently swallow). Call storageAvailable() to branch.
 *
 * The helpers are driver-agnostic — identical behaviour on local disk or S3.
 * You may pass an explicit $request to any helper to override the stored one.
 */
trait InteractsWithStorage
{
    use HasRequest;

    /** The request-scoped StoragePort, or null when the Storage plugin is not loaded. */
    protected function storage(?Request $request = null): ?StoragePort
    {
        $container = $this->resolveRequest($request)->container();

        if ($container === null || !$container->has(StoragePort::class)) {
            return null;
        }

        $storage = $container->make(StoragePort::class);

        return $storage instanceof StoragePort ? $storage : null;
    }

    /** Whether a StoragePort is bound for this request. */
    protected function storageAvailable(?Request $request = null): bool
    {
        return $this->storage($request) !== null;
    }

    /** Resolve the StoragePort or throw — internal guard for the write helpers. */
    private function requireStorage(?Request $request = null): StoragePort
    {
        return $this->storage($request)
            ?? throw new \RuntimeException(
                'StoragePort is unbound. Configure STORAGE_DRIVER + STORAGE_ROOT and ensure the route '
                . 'declares "requires": ["storage.local"].',
            );
    }

    /**
     * Persist a multipart upload from $field under $path with a random,
     * collision-free name (keeps the original extension). Returns the stored
     * relative path, or null when the field is absent / the upload is invalid.
     */
    protected function storeUpload(
        string $field,
        string $path = 'uploads',
        string $visibility = 'private',
        ?Request $request = null,
    ): ?string {
        $file = $this->resolveRequest($request)->file($field);
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return null;
        }

        $name = bin2hex(random_bytes(8)) . ($file->extension() !== '' ? '.' . $file->extension() : '');

        return $this->requireStorage($request)->store($file->contents(), $name, $path, $visibility);
    }

    /**
     * Persist a multipart upload KEEPING its original client filename
     * (basename-sanitised). Use when the displayed name matters; prefer
     * storeUpload() when you only need a unique key. Null when absent/invalid.
     */
    protected function storeUploadAs(
        string $field,
        string $path = 'uploads',
        string $visibility = 'private',
        ?Request $request = null,
    ): ?string {
        $file = $this->resolveRequest($request)->file($field);
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return null;
        }

        return $this->requireStorage($request)->store($file->contents(), basename($file->clientName()), $path, $visibility);
    }

    /**
     * Decode a base64 string and persist it as $filename under $path. Returns
     * the stored path, or null when the payload is not valid base64.
     */
    protected function storeBase64(
        string $encoded,
        string $filename,
        string $path = 'uploads',
        string $visibility = 'private',
        ?Request $request = null,
    ): ?string {
        $decoded = base64_decode($encoded, true);   // strict — reject malformed input
        if ($encoded === '' || $decoded === false) {
            return null;
        }

        return $this->requireStorage($request)->store($decoded, basename($filename), $path, $visibility);
    }

    /** Persist raw bytes directly. Returns the stored relative path. */
    protected function storeContents(
        string $contents,
        string $filename,
        string $path = '',
        string $visibility = 'private',
        ?Request $request = null,
    ): string {
        return $this->requireStorage($request)->store($contents, basename($filename), $path, $visibility);
    }

    /** Read a stored blob's contents, or null when it is missing / storage is absent. */
    protected function readFile(string $path, ?Request $request = null): ?string
    {
        $storage = $this->storage($request);
        if ($storage === null || !$storage->exists($path)) {
            return null;
        }

        return $storage->get($path);
    }

    /** Whether a stored blob exists (false when storage is absent). */
    protected function fileExists(string $path, ?Request $request = null): bool
    {
        return $this->storage($request)?->exists($path) ?? false;
    }

    /** Delete a stored blob. True on success (or already-absent); false when storage is absent. */
    protected function deleteFile(string $path, ?Request $request = null): bool
    {
        return $this->storage($request)?->delete($path) ?? false;
    }

    /** A signed, expiring URL for a stored blob, or null when storage is absent. */
    protected function fileUrl(string $path, int $expiresInSeconds = 3600, ?Request $request = null): ?string
    {
        return $this->storage($request)?->temporaryUrl($path, $expiresInSeconds);
    }

    /**
     * Copy a stored blob to $to (source kept). Returns the destination path, or
     * null when the source is missing / storage is absent.
     */
    protected function copyFile(string $from, string $to, string $visibility = 'private', ?Request $request = null): ?string
    {
        return $this->relocate($from, $to, deleteSource: false, visibility: $visibility, request: $request);
    }

    /**
     * Move a stored blob to $to (source removed). Returns the destination path,
     * or null when the source is missing / storage is absent.
     */
    protected function moveFile(string $from, string $to, string $visibility = 'private', ?Request $request = null): ?string
    {
        return $this->relocate($from, $to, deleteSource: true, visibility: $visibility, request: $request);
    }

    /**
     * Shared copy/move primitive — StoragePort has no native copy, so read the
     * source blob and re-store it at the destination (deleting the source for a
     * move). Driver-agnostic across local disk and S3.
     */
    private function relocate(string $from, string $to, bool $deleteSource, string $visibility, ?Request $request): ?string
    {
        $storage = $this->storage($request);
        if ($storage === null || !$storage->exists($from)) {
            return null;
        }

        $slash    = strrpos($to, '/');
        $destDir  = $slash === false ? '' : substr($to, 0, $slash);
        $destName = $slash === false ? $to : substr($to, $slash + 1);

        $stored = $storage->store($storage->get($from), $destName, $destDir, $visibility);

        if ($deleteSource) {
            $storage->delete($from);
        }

        return $stored;
    }
}
