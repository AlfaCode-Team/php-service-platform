<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Http;

use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

/**
 * UploadedFile — representation of a single uploaded file, backed by Symfony.
 *
 * Reuses Symfony's secure move/validation handling while exposing the kernel's
 * concise API. Persisting the file is the responsibility of a Gateway/StoragePort;
 * this object describes the upload and can move/stream it.
 */
final class UploadedFile extends SymfonyUploadedFile
{
    /**
     * Promote a base Symfony uploaded file (PHP-FPM / real upload) into a kernel
     * UploadedFile.
     *
     * `$test` defaults to FALSE so moveTo() keeps the `is_uploaded_file()` safety
     * check that distinguishes a genuine multipart upload from an arbitrary path.
     * Only pass true for synthetic uploads (tests, or non-SAPI transports — see
     * fromSwoole()).
     */
    public static function createFromBase(SymfonyUploadedFile $file, bool $test = false): static
    {
        return new static(
            $file->getPathname(),
            $file->getClientOriginalName(),
            $file->getClientMimeType(),
            $file->getError(),
            $test,
        );
    }

    /**
     * Build a kernel UploadedFile from an OpenSwoole file entry.
     *
     * Swoole's per-file array is $_FILES-shaped (name/type/tmp_name/error/size)
     * but the temp file was NOT created by PHP's multipart handler, so
     * `is_uploaded_file()` would reject it. We therefore construct in test mode
     * (test=true) so moveTo() uses rename() instead of move_uploaded_file().
     *
     * @param array{name?: string, type?: string, tmp_name?: string, error?: int} $file
     */
    public static function fromSwoole(array $file): static
    {
        return new static(
            $file['tmp_name'] ?? '',
            $file['name']     ?? '',
            $file['type']     ?? null,
            $file['error']    ?? UPLOAD_ERR_OK,
            true,
        );
    }

    public function clientName(): string
    {
        return $this->getClientOriginalName();
    }

    public function clientMimeType(): string
    {
        return $this->getClientMimeType();
    }

    public function size(): int
    {
        return (int) $this->getSize();
    }

    public function tempPath(): string
    {
        return $this->getPathname();
    }

    public function isValid(): bool
    {
        return parent::isValid();
    }

    public function contents(): string
    {
        $path = $this->getPathname();

        return is_readable($path) ? (file_get_contents($path) ?: '') : '';
    }

    public function extension(): string
    {
        return strtolower($this->getClientOriginalExtension());
    }
}
