<?php

declare(strict_types=1);

namespace Plugins\Storage\Infrastructure;

use Aws\S3\S3Client;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort;
use League\Flysystem\Filesystem;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Visibility;
use League\Flysystem\FilesystemException;

/**
 * S3-compatible StoragePort adapter (AWS S3, DigitalOcean Spaces, Cloudflare R2,
 * MinIO, Scaleway, …) built on the secure, maintained league/flysystem-aws-s3-v3
 * package (0 security advisories).
 *
 * Sibling to LocalStorageAdapter — selected by STORAGE_DRIVER=s3. The rest of
 * the framework depends only on the StoragePort interface, so swapping the disk
 * for a bucket changes nothing upstream.
 *
 * temporaryUrl() delegates to the SDK's native pre-signed URL (real, verifiable
 * S3 expiry) rather than the HMAC scheme the local adapter must synthesise.
 */
final class S3StorageAdapter implements StoragePort
{
    private readonly Filesystem $fs;

    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
    ) {
        $this->fs = new Filesystem(new AwsS3V3Adapter($client, $bucket));
    }

    /**
     * Build from configuration. $endpoint/$usePathStyle support non-AWS,
     * S3-compatible providers (Spaces, R2, MinIO).
     */
    public static function fromConfig(
        string $bucket,
        string $region,
        string $key,
        string $secret,
        ?string $endpoint = null,
        bool $usePathStyle = false,
    ): self {
        $config = [
            'version'     => 'latest',
            'region'      => $region,
            'credentials' => ['key' => $key, 'secret' => $secret],
        ];
        if ($endpoint !== null && $endpoint !== '') {
            $config['endpoint']                 = $endpoint;
            $config['use_path_style_endpoint']  = $usePathStyle;
        }

        return new self(new S3Client($config), $bucket);
    }

    public function store(string $contents, string $filename, string $path = '', string $visibility = 'private'): string
    {
        $key = $this->join($path, $filename);
        try {
            $this->fs->write($key, $contents, [
                'visibility' => $visibility === 'public' ? Visibility::PUBLIC : Visibility::PRIVATE,
            ]);
        } catch (FilesystemException $e) {
            throw new \RuntimeException("Storage(S3): unable to store [{$key}]: {$e->getMessage()}", previous: $e);
        }
        return $key;
    }

    public function get(string $path): string
    {
        try {
            return $this->fs->read($path);
        } catch (FilesystemException $e) {
            throw new \RuntimeException("Storage(S3): unable to read [{$path}]: {$e->getMessage()}", previous: $e);
        }
    }

    public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string
    {
        $command = $this->client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key'    => ltrim($path, '/'),
        ]);
        $request = $this->client->createPresignedRequest($command, '+' . max(1, $expiresInSeconds) . ' seconds');

        return (string) $request->getUri();
    }

    public function exists(string $path): bool
    {
        try {
            return $this->fs->fileExists($path);
        } catch (FilesystemException) {
            return false;
        }
    }

    public function delete(string $path): bool
    {
        try {
            $this->fs->delete($path);
            return true;
        } catch (FilesystemException) {
            return false;
        }
    }

    private function join(string $path, string $filename): string
    {
        $path = trim($path, '/');
        return $path === '' ? $filename : $path . '/' . $filename;
    }
}
