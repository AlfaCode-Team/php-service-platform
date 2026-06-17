<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Crypto;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\KernelException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Crypto\Infrastructure\AesEncrypter;

#[CoversClass(AesEncrypter::class)]
final class AesEncrypterTest extends TestCase
{
    public function test_round_trips_a_serialized_value(): void
    {
        $enc = new AesEncrypter([random_bytes(32)]);
        $payload = ['secret' => 'data', 'n' => 42];

        $this->assertSame($payload, $enc->decrypt($enc->encrypt($payload)));
    }

    public function test_round_trips_a_plain_string(): void
    {
        $enc = new AesEncrypter([random_bytes(32)]);

        $this->assertSame('hello', $enc->decryptString($enc->encryptString('hello')));
    }

    public function test_old_ciphertext_decrypts_after_key_rotation(): void
    {
        $k1 = random_bytes(32);
        $k2 = random_bytes(32);

        $cipher = (new AesEncrypter([$k1]))->encrypt('x');
        // Rotate: k2 is now primary, k1 retained as previous.
        $rotated = new AesEncrypter([$k2, $k1]);

        $this->assertSame('x', $rotated->decrypt($cipher));
    }

    public function test_tampered_payload_is_rejected(): void
    {
        $enc = new AesEncrypter([random_bytes(32)]);
        $cipher = $enc->encrypt('x');
        $tampered = base64_encode(strtr(base64_decode($cipher), 'a', 'b'));

        $this->expectException(KernelException::class);
        $enc->decrypt($tampered);
    }

    public function test_rejects_wrong_size_key(): void
    {
        $this->expectException(KernelException::class);
        new AesEncrypter([random_bytes(16)]);
    }

    public function test_requires_at_least_one_key(): void
    {
        $this->expectException(KernelException::class);
        new AesEncrypter([]);
    }
}
