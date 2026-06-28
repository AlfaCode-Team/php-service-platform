<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Application\Ports;

use Plugins\OAuth2\Domain\Entities\AuthCode;

interface AuthCodeStore
{
    public function store(AuthCode $code, string $codeHash): void;

    public function findByHash(string $codeHash): ?AuthCode;

    /**
     * Atomically mark a code consumed. Returns false if it was already consumed
     * (single-use enforcement / replay detection).
     */
    public function consume(string $codeId): bool;

    public function deleteExpired(?\DateTimeImmutable $now = null): int;
}
