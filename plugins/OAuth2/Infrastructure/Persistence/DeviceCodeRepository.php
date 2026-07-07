<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\OAuth2\Application\Ports\DeviceCodeStore;
use Plugins\OAuth2\Domain\Entities\DeviceCode;

final class DeviceCodeRepository implements DeviceCodeStore
{
    public function __construct(private readonly DatabasePort $db)
    {
    }

    public function store(DeviceCode $device, string $deviceCodeHash): void
    {
        try {
            $this->db->execute(
                'INSERT INTO oauth_device_codes
                    (id, device_code_hash, user_code, client_id, scopes, status, user_id, interval_seconds, expires_at, created_at)
                 VALUES (:id, :hash, :user_code, :client, :scopes, :status, :user, :interval, :expires, :created)',
                [
                    'id'        => $device->id,
                    'hash'      => $deviceCodeHash,
                    'user_code' => $device->userCode,
                    'client'    => $device->clientId,
                    'scopes'    => json_encode(array_values($device->scopes)),
                    'status'    => $device->status,
                    'user'      => $device->userId,
                    'interval'  => $device->interval,
                    'expires'   => $device->expiresAt->format('Y-m-d H:i:s'),
                    'created'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to store device code', layer: 'repository.oauth', previous: $e);
        }
    }

    public function findByDeviceHash(string $deviceCodeHash): ?DeviceCode
    {
        return $this->hydrateOne('device_code_hash = :v', ['v' => $deviceCodeHash]);
    }

    public function findByUserCode(string $userCode): ?DeviceCode
    {
        return $this->hydrateOne('user_code = :v', ['v' => $userCode]);
    }

    public function authorize(string $id, string $userId): bool
    {
        try {
            return $this->db->execute(
                "UPDATE oauth_device_codes SET status = 'authorized', user_id = :user
                 WHERE id = :id AND status = 'pending'",
                ['user' => $userId, 'id' => $id],
            ) === 1;
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to authorize device code', layer: 'repository.oauth', previous: $e);
        }
    }

    public function deny(string $id): bool
    {
        try {
            return $this->db->execute(
                "UPDATE oauth_device_codes SET status = 'denied' WHERE id = :id AND status = 'pending'",
                ['id' => $id],
            ) === 1;
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to deny device code', layer: 'repository.oauth', previous: $e);
        }
    }

    public function markPolled(string $id, \DateTimeImmutable $at): void
    {
        try {
            $this->db->execute(
                'UPDATE oauth_device_codes SET last_polled_at = :at WHERE id = :id',
                ['at' => $at->format('Y-m-d H:i:s'), 'id' => $id],
            );
        } catch (\PDOException) {
            // non-fatal — slow_down enforcement degrades gracefully
        }
    }

    public function consume(string $id): bool
    {
        try {
            return $this->db->execute(
                "UPDATE oauth_device_codes SET status = 'denied' WHERE id = :id AND status = 'authorized'",
                ['id' => $id],
            ) === 1;
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to consume device code', layer: 'repository.oauth', previous: $e);
        }
    }

    public function deleteExpired(?\DateTimeImmutable $now = null): int
    {
        $cutoff = ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            return $this->db->execute(
                'DELETE FROM oauth_device_codes WHERE expires_at <= :cutoff',
                ['cutoff' => $cutoff],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to prune device codes', layer: 'repository.oauth', previous: $e);
        }
    }

    private function hydrateOne(string $where, array $params): ?DeviceCode
    {
        try {
            $row = $this->db->queryOne("SELECT * FROM oauth_device_codes WHERE {$where}", $params);
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to load device code', layer: 'repository.oauth', previous: $e);
        }

        if ($row === null) {
            return null;
        }

        $scopes = json_decode((string) ($row['scopes'] ?? '[]'), true);

        return DeviceCode::of(
            id:           (string) $row['id'],
            userCode:     (string) $row['user_code'],
            clientId:     (string) $row['client_id'],
            scopes:       is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [],
            status:       (string) $row['status'],
            userId:       ($row['user_id'] ?? null) ?: null,
            interval:     (int) $row['interval_seconds'],
            lastPolledAt: ($row['last_polled_at'] ?? null) ? new \DateTimeImmutable((string) $row['last_polled_at']) : null,
            expiresAt:    new \DateTimeImmutable((string) $row['expires_at']),
        );
    }
}
