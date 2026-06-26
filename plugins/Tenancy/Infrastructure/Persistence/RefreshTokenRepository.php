<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Tenancy\Application\Ports\RefreshTokenStore;
use Plugins\Tenancy\Domain\Entities\RefreshTokenRecord;

/**
 * RefreshTokenRepository — central `refresh_tokens`. DatabasePort ONLY; the
 * injected port is the central (control-plane) connection. Only token hashes
 * are stored/compared — never raw tokens.
 */
final class RefreshTokenRepository implements RefreshTokenStore
{
    public function __construct(
        private readonly DatabasePort $central,
    ) {}

    public function store(
        string $tokenId,
        string $familyId,
        string $userId,
        string $tokenHash,
        ?string $tenantId,
        ?string $device,
        ?string $ip,
        \DateTimeImmutable $expiresAt,
    ): void {
        try {
            $this->central->execute(
                'INSERT INTO refresh_tokens
                    (token_id, family_id, user_id, token_hash, tenant_id, device, ip, expires_at, created_at)
                 VALUES (:tid, :fid, :uid, :hash, :tenant, :device, :ip, :exp, :now)',
                [
                    'tid'    => $tokenId,
                    'fid'    => $familyId,
                    'uid'    => $userId,
                    'hash'   => $tokenHash,
                    'tenant' => ($tenantId === null || $tenantId === '') ? null : $tenantId,
                    'device' => $device,
                    'ip'     => $ip,
                    'exp'    => $expiresAt->format('Y-m-d H:i:s'),
                    'now'    => self::now(),
                ],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to store refresh token.', layer: 'repository.tenancy', previous: $e);
        }
    }

    public function findActiveByHash(string $tokenHash): ?RefreshTokenRecord
    {
        try {
            $row = $this->central->queryOne(
                'SELECT token_id, family_id, user_id, tenant_id, revoked_at
                   FROM refresh_tokens
                  WHERE token_hash = :hash
                    AND revoked_at IS NULL
                    AND expires_at > :now
                  LIMIT 1',
                ['hash' => $tokenHash, 'now' => self::now()],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to load refresh token.', layer: 'repository.tenancy', previous: $e);
        }

        return $row === null ? null : RefreshTokenRecord::fromRow($row);
    }

    public function findByHash(string $tokenHash): ?RefreshTokenRecord
    {
        try {
            // Note: still bounded by expiry — an expired token is just "unknown",
            // not a reuse signal. Revoked-but-unexpired is the replay case.
            $row = $this->central->queryOne(
                'SELECT token_id, family_id, user_id, tenant_id, revoked_at
                   FROM refresh_tokens
                  WHERE token_hash = :hash
                    AND expires_at > :now
                  LIMIT 1',
                ['hash' => $tokenHash, 'now' => self::now()],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to load refresh token.', layer: 'repository.tenancy', previous: $e);
        }

        return $row === null ? null : RefreshTokenRecord::fromRow($row);
    }

    public function revoke(string $tokenId): void
    {
        $this->revokeIfActive($tokenId);
    }

    public function revokeIfActive(string $tokenId): bool
    {
        try {
            $affected = $this->central->execute(
                'UPDATE refresh_tokens SET revoked_at = :now WHERE token_id = :tid AND revoked_at IS NULL',
                ['now' => self::now(), 'tid' => $tokenId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to revoke refresh token.', layer: 'repository.tenancy', previous: $e);
        }

        return $affected > 0;
    }

    public function revokeFamily(string $familyId): int
    {
        try {
            return $this->central->execute(
                'UPDATE refresh_tokens SET revoked_at = :now WHERE family_id = :fid AND revoked_at IS NULL',
                ['now' => self::now(), 'fid' => $familyId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to revoke token family.', layer: 'repository.tenancy', previous: $e);
        }
    }

    public function revokeAllForUser(string $userId): int
    {
        try {
            return $this->central->execute(
                'UPDATE refresh_tokens SET revoked_at = :now WHERE user_id = :uid AND revoked_at IS NULL',
                ['now' => self::now(), 'uid' => $userId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to revoke refresh tokens.', layer: 'repository.tenancy', previous: $e);
        }
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
