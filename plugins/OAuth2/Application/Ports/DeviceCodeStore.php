<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Application\Ports;

use Plugins\OAuth2\Domain\Entities\DeviceCode;

interface DeviceCodeStore
{
    public function store(DeviceCode $device, string $deviceCodeHash): void;

    public function findByDeviceHash(string $deviceCodeHash): ?DeviceCode;

    public function findByUserCode(string $userCode): ?DeviceCode;

    /** Authorize the request for a user (pending → authorized). False if not pending. */
    public function authorize(string $id, string $userId): bool;

    /** Deny the request (pending → denied). */
    public function deny(string $id): bool;

    /** Record a poll timestamp (drives slow_down interval enforcement). */
    public function markPolled(string $id, \DateTimeImmutable $at): void;

    /** Consume an authorized device code so the access token is issued once. */
    public function consume(string $id): bool;

    public function deleteExpired(?\DateTimeImmutable $now = null): int;
}
