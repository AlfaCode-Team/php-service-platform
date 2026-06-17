<?php
declare(strict_types=1);
namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * Symmetric encryption port — encrypt/decrypt at-rest data (tokens, PII).
 *
 * Implementations MUST be authenticated (tamper-evident) and MUST support key
 * rotation so old ciphertext stays decryptable after a key change.
 */
interface EncryptionPort
{
    /**
     * Encrypt a value. When $serialize is true, non-string values are
     * serialized first so any payload round-trips.
     */
    public function encrypt(mixed $value, bool $serialize = true): string;

    /**
     * Decrypt a payload produced by encrypt(). Throws on tampering or an
     * unknown key.
     */
    public function decrypt(string $payload, bool $unserialize = true): mixed;

    /**
     * Encrypt a plain string without serialization (convenience).
     */
    public function encryptString(string $value): string;

    /**
     * Decrypt a string produced by encryptString().
     */
    public function decryptString(string $payload): string;
}
