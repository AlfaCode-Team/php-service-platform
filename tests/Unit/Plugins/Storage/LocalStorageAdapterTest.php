<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Storage\Infrastructure\LocalStorageAdapter;

#[CoversClass(LocalStorageAdapter::class)]
final class LocalStorageAdapterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psp-storage-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
    }

    private function adapter(string $urlBase = '', string $secret = ''): LocalStorageAdapter
    {
        return new LocalStorageAdapter($this->root, $urlBase, $secret);
    }

    public function test_store_writes_file_and_returns_relative_path(): void
    {
        $path = $this->adapter()->store('hello world', 'note.txt', 'docs');

        $this->assertSame('docs/note.txt', $path);
        $this->assertFileExists($this->root . '/docs/note.txt');
        $this->assertSame('hello world', file_get_contents($this->root . '/docs/note.txt'));
    }

    public function test_store_without_path_uses_filename_only(): void
    {
        $this->assertSame('a.txt', $this->adapter()->store('x', 'a.txt'));
    }

    public function test_visibility_sets_file_mode(): void
    {
        $adapter = $this->adapter();
        $adapter->store('p', 'pub.txt', '', 'public');
        $adapter->store('s', 'sec.txt', '', 'private');

        $this->assertSame('0644', substr(sprintf('%o', fileperms($this->root . '/pub.txt')), -4));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->root . '/sec.txt')), -4));
    }

    public function test_get_returns_contents(): void
    {
        $adapter = $this->adapter();
        $adapter->store('payload', 'f.txt');

        $this->assertSame('payload', $adapter->get('f.txt'));
    }

    public function test_get_missing_file_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->adapter()->get('nope.txt');
    }

    public function test_exists_and_delete(): void
    {
        $adapter = $this->adapter();
        $adapter->store('x', 'f.txt');

        $this->assertTrue($adapter->exists('f.txt'));
        $this->assertTrue($adapter->delete('f.txt'));
        $this->assertFalse($adapter->exists('f.txt'));
    }

    public function test_delete_missing_file_is_idempotent(): void
    {
        $this->assertTrue($this->adapter()->delete('never-existed.txt'));
    }

    public function test_store_is_atomic_no_temp_files_left(): void
    {
        $adapter = $this->adapter();
        $adapter->store('content', 'f.txt', 'dir');

        $leftovers = glob($this->root . '/dir/.*tmp') ?: [];
        $this->assertSame([], $leftovers, 'temp files must not be left behind');
    }

    public function test_store_stream_writes_contents(): void
    {
        $resource = fopen('php://temp', 'r+b');
        fwrite($resource, 'streamed bytes');
        rewind($resource);

        $path = $this->adapter()->storeStream($resource, 'big.bin', 'uploads');
        fclose($resource);

        $this->assertSame('uploads/big.bin', $path);
        $this->assertSame('streamed bytes', file_get_contents($this->root . '/uploads/big.bin'));
    }

    public function test_store_stream_rejects_non_resource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line intentional bad arg */
        $this->adapter()->storeStream('not-a-resource', 'x.txt');
    }

    public function test_read_stream_returns_readable_handle(): void
    {
        $adapter = $this->adapter();
        $adapter->store('chunked', 'c.txt');

        $handle = $adapter->readStream('c.txt');
        $this->assertIsResource($handle);
        $this->assertSame('chunked', stream_get_contents($handle));
        fclose($handle);
    }

    public function test_read_stream_missing_file_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->adapter()->readStream('absent.txt');
    }

    public function test_path_traversal_is_rejected_on_read(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('traversal');
        $this->adapter()->get('../../etc/passwd');
    }

    public function test_path_traversal_is_rejected_on_store(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->adapter()->store('x', 'passwd', '../../etc');
    }

    public function test_temporary_url_without_secret_is_unsigned(): void
    {
        $url = $this->adapter('https://cdn.example.com/files')->temporaryUrl('docs/a.txt');

        $this->assertSame('https://cdn.example.com/files/docs/a.txt', $url);
    }

    public function test_temporary_url_with_secret_is_signed_and_verifiable(): void
    {
        $adapter = $this->adapter('https://cdn.example.com/files', 'super-secret');
        $url     = $adapter->temporaryUrl('docs/a.txt', 3600);

        $this->assertStringContainsString('expires=', $url);
        $this->assertStringContainsString('signature=', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertTrue($adapter->verifyTemporaryUrl('docs/a.txt', (int) $q['expires'], $q['signature']));
    }

    public function test_verify_temporary_url_rejects_tampered_signature(): void
    {
        $adapter = $this->adapter('https://cdn.example.com/files', 'super-secret');

        $this->assertFalse($adapter->verifyTemporaryUrl('docs/a.txt', time() + 3600, 'deadbeef'));
    }

    public function test_verify_temporary_url_rejects_expired(): void
    {
        $adapter   = $this->adapter('https://cdn.example.com/files', 'super-secret');
        $expires   = time() - 10;
        $signature = hash_hmac('sha256', 'docs/a.txt|' . $expires, 'super-secret');

        $this->assertFalse($adapter->verifyTemporaryUrl('docs/a.txt', $expires, $signature));
    }

    public function test_verify_temporary_url_without_secret_always_false(): void
    {
        $this->assertFalse(
            $this->adapter('https://cdn.example.com/files')->verifyTemporaryUrl('a.txt', time() + 60, 'x')
        );
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            is_dir($full) ? $this->deleteTree($full) : @unlink($full);
        }
        @rmdir($dir);
    }
}
