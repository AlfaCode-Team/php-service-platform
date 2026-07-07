<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Storage;

use Aws\S3\S3Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Storage\Infrastructure\S3StorageAdapter;

#[CoversClass(S3StorageAdapter::class)]
final class S3StorageAdapterTest extends TestCase
{
    /** Pull the private S3Client out of an adapter for white-box assertions. */
    private function clientOf(S3StorageAdapter $adapter): S3Client
    {
        $ref  = new \ReflectionProperty(S3StorageAdapter::class, 'client');
        return $ref->getValue($adapter);
    }

    public function test_from_config_applies_region(): void
    {
        $adapter = S3StorageAdapter::fromConfig('bucket', 'eu-west-1', 'KEY', 'SECRET');

        $this->assertSame('eu-west-1', $this->clientOf($adapter)->getRegion());
    }

    public function test_from_config_applies_custom_endpoint_for_compatible_providers(): void
    {
        $adapter = S3StorageAdapter::fromConfig(
            bucket: 'space',
            region: 'us-east-1',
            key: 'KEY',
            secret: 'SECRET',
            endpoint: 'https://nyc3.digitaloceanspaces.com',
            usePathStyle: true,
        );

        $this->assertSame(
            'https://nyc3.digitaloceanspaces.com',
            (string) $this->clientOf($adapter)->getEndpoint(),
        );
    }

    public function test_explicit_static_credentials_are_used(): void
    {
        $adapter = S3StorageAdapter::fromConfig('bucket', 'us-east-1', 'AKIA-TEST', 'shh');

        $creds = $this->clientOf($adapter)->getCredentials()->wait();
        $this->assertSame('AKIA-TEST', $creds->getAccessKeyId());
        $this->assertSame('shh', $creds->getSecretKey());
    }

    public function test_empty_key_does_not_force_static_credentials(): void
    {
        // With no static key the SDK default provider chain must own credential
        // resolution (IAM roles, env, SSO). We assert fromConfig does NOT inject
        // an empty-string credential set that would shadow that chain.
        putenv('AWS_ACCESS_KEY_ID=ENV-KEY');
        putenv('AWS_SECRET_ACCESS_KEY=ENV-SECRET');
        $_SERVER['AWS_ACCESS_KEY_ID'] = 'ENV-KEY';
        $_SERVER['AWS_SECRET_ACCESS_KEY'] = 'ENV-SECRET';

        try {
            $adapter = S3StorageAdapter::fromConfig('bucket', 'us-east-1', '', '');
            $creds   = $this->clientOf($adapter)->getCredentials()->wait();

            $this->assertSame('ENV-KEY', $creds->getAccessKeyId());
        } finally {
            putenv('AWS_ACCESS_KEY_ID');
            putenv('AWS_SECRET_ACCESS_KEY');
            unset($_SERVER['AWS_ACCESS_KEY_ID'], $_SERVER['AWS_SECRET_ACCESS_KEY']);
        }
    }
}
