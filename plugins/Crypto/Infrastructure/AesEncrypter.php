<?php

declare(strict_types=1);

namespace Plugins\Crypto\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\KernelException;

/**
 * Authenticated AES-256-GCM encrypter with key rotation.
 *
 * Payload format (base64-encoded JSON): { iv, value, tag, kid } where `kid` is
 * the index of the key used. Decryption tries the keyed entry first, then any
 * remaining keys, so rotating in a new primary key keeps old ciphertext valid.
 *
 * Construct with one or more 32-byte keys; the first is the encryption (current)
 * key, the rest are previous keys kept only for decryption.
 */
final class AesEncrypter implements EncryptionPort
{
    private const CIPHER = 'aes-256-gcm';

    /** @var list<string> raw 32-byte keys; index 0 is current */
    private readonly array $keys;

    /**
     * @param string|list<string> $keys base64 (with optional "base64:" prefix) or raw 32-byte keys
     */
    public function __construct(string|array $keys)
    {
        $normalized = array_map([self::class, 'normalizeKey'], (array) $keys);
        $normalized = array_values(array_filter($normalized, static fn(string $k) => $k !== ''));

        if ($normalized === []) {
            throw new KernelException('AesEncrypter requires at least one 32-byte key.', layer: 'crypto.encrypter');
        }
        foreach ($normalized as $k) {
            if (strlen($k) !== 32) {
                throw new KernelException('AesEncrypter keys must be exactly 32 bytes (AES-256).', layer: 'crypto.encrypter');
            }
        }
        $this->keys = $normalized;
    }

    public function encrypt(mixed $value, bool $serialize = true): string
    {
        $plaintext = $serialize ? serialize($value) : (string) $value;
        $iv = random_bytes(12); // 96-bit nonce for GCM
        $tag = '';

        $cipher = openssl_encrypt($plaintext, self::CIPHER, $this->keys[0], OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new KernelException('Encryption failed.', layer: 'crypto.encrypter');
        }

        $json = json_encode([
            'iv'    => base64_encode($iv),
            'value' => base64_encode($cipher),
            'tag'   => base64_encode($tag),
            'kid'   => 0,
        ], JSON_THROW_ON_ERROR);

        return base64_encode($json);
    }

    public function decrypt(string $payload, bool $unserialize = true): mixed
    {
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            throw new KernelException('Malformed encryption payload.', layer: 'crypto.encrypter');
        }

        try {
            $parts = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new KernelException('Malformed encryption payload.', layer: 'crypto.encrypter', previous: $e);
        }
        if (!is_array($parts) || !isset($parts['iv'], $parts['value'], $parts['tag'])) {
            throw new KernelException('Malformed encryption payload.', layer: 'crypto.encrypter');
        }

        $iv     = base64_decode((string) $parts['iv'], true);
        $cipher = base64_decode((string) $parts['value'], true);
        $tag    = base64_decode((string) $parts['tag'], true);
        if ($iv === false || $cipher === false || $tag === false) {
            throw new KernelException('Malformed encryption payload.', layer: 'crypto.encrypter');
        }

        // Try the recorded key first, then every other key (rotation support).
        $order = $this->keyTryOrder(is_int($parts['kid'] ?? null) ? $parts['kid'] : 0);
        foreach ($order as $kid) {
            $plain = openssl_decrypt($cipher, self::CIPHER, $this->keys[$kid], OPENSSL_RAW_DATA, $iv, $tag);
            if ($plain !== false) {
                return $unserialize ? unserialize($plain) : $plain;
            }
        }

        throw new KernelException('Could not decrypt payload (invalid key or tampered data).', layer: 'crypto.encrypter');
    }

    public function encryptString(string $value): string
    {
        return $this->encrypt($value, false);
    }

    public function decryptString(string $payload): string
    {
        return (string) $this->decrypt($payload, false);
    }

    /** @return list<int> */
    private function keyTryOrder(int $preferred): array
    {
        $indices = array_keys($this->keys);
        if (!isset($this->keys[$preferred])) {
            return $indices;
        }
        return [$preferred, ...array_values(array_filter($indices, static fn(int $i) => $i !== $preferred))];
    }

    private static function normalizeKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            $key = substr($key, 7);
        }
        $decoded = base64_decode($key, true);
        // If it decodes cleanly to 32 bytes, treat as base64; else use raw.
        return ($decoded !== false && strlen($decoded) === 32) ? $decoded : $key;
    }
}
