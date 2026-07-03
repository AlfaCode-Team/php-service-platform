<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Tenancy;

use PHPUnit\Framework\TestCase;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Tenancy\Application\Ports\AuditSink;
use Plugins\Tenancy\Application\Ports\MembershipReader;
use Plugins\Tenancy\Application\Ports\RefreshTokenStore;
use Plugins\Tenancy\Application\Services\RefreshTokenService;
use Plugins\Tenancy\Domain\Entities\Membership;
use Plugins\Tenancy\Domain\Entities\RefreshTokenRecord;
use Plugins\Tenancy\Domain\Exceptions\InvalidRefreshTokenException;
use Plugins\Tenancy\Domain\ValueObjects\MembershipStatus;
use Plugins\Tenancy\Domain\ValueObjects\TenantStatus;

final class RefreshTokenServiceTest extends TestCase
{
    private function store(): RefreshTokenStore
    {
        return new class implements RefreshTokenStore {
            /** @var array<string, array<string, mixed>> keyed by token_id */
            public array $rows = [];
            public function store(string $tokenId, string $familyId, string $userId, string $tokenHash, ?string $tenantId, ?string $device, ?string $ip, \DateTimeImmutable $expiresAt): void
            {
                $this->rows[$tokenId] = [
                    'token_id' => $tokenId, 'family_id' => $familyId, 'user_id' => $userId, 'hash' => $tokenHash,
                    'tenant_id' => $tenantId, 'revoked' => false, 'exp' => $expiresAt,
                ];
            }
            private function toRecord(array $r): RefreshTokenRecord
            {
                return RefreshTokenRecord::of($r['token_id'], $r['user_id'], $r['tenant_id'], $r['family_id'], $r['revoked']);
            }
            public function findActiveByHash(string $tokenHash): ?RefreshTokenRecord
            {
                foreach ($this->rows as $r) {
                    if ($r['hash'] === $tokenHash && !$r['revoked'] && $r['exp'] > new \DateTimeImmutable()) {
                        return $this->toRecord($r);
                    }
                }
                return null;
            }
            public function findByHash(string $tokenHash): ?RefreshTokenRecord
            {
                foreach ($this->rows as $r) {
                    if ($r['hash'] === $tokenHash && $r['exp'] > new \DateTimeImmutable()) {
                        return $this->toRecord($r);
                    }
                }
                return null;
            }
            public function revoke(string $tokenId): void
            {
                $this->revokeIfActive($tokenId);
            }
            public function revokeIfActive(string $tokenId): bool
            {
                if (isset($this->rows[$tokenId]) && !$this->rows[$tokenId]['revoked']) {
                    $this->rows[$tokenId]['revoked'] = true;
                    return true;
                }
                return false;
            }
            public function revokeFamily(string $familyId): int
            {
                $n = 0;
                foreach ($this->rows as $id => $r) {
                    if ($r['family_id'] === $familyId && !$r['revoked']) { $this->rows[$id]['revoked'] = true; $n++; }
                }
                return $n;
            }
            public function revokeAllForUser(string $userId): int
            {
                $n = 0;
                foreach ($this->rows as $id => $r) {
                    if ($r['user_id'] === $userId && !$r['revoked']) { $this->rows[$id]['revoked'] = true; $n++; }
                }
                return $n;
            }
        };
    }

    private function auth(): AuthServiceContract
    {
        return new class implements AuthServiceContract {
            public function issueJwt(string $userId, array $claims = [], int $ttlSeconds = 3600): string
            {
                return 'access:' . $userId . ':' . ($claims['tnt'] ?? '') . ':' . implode(',', $claims['roles'] ?? []);
            }
            public function createPersonalAccessToken(string $userId, string $name = 'default', array $abilities = [], ?int $ttlSeconds = null): array { return ['id' => 'i', 'token' => 't']; }
            public function revokePersonalAccessToken(string $id): void {}
            public function startSession(\AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort $session, string $userId, array $roles = [], array $permissions = [], string $tenantId = ''): void {}
            public function endSession(\AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort $session): void {}
            public function revokeJwt(string $jti, int $ttlSeconds = 3600): void {}
            public function hashPassword(string $plain): string { return $plain; }
            public function verifyPassword(string $plain, string $hash): bool { return true; }
        };
    }

    /** @param array<string, Membership> $seats keyed by "user|tenant" */
    private function reader(array $seats = []): MembershipReader
    {
        return new class($seats) implements MembershipReader {
            public function __construct(private array $seats) {}
            public function activeForUser(string $userId): array { return []; }
            public function find(string $userId, string $tenantId): ?Membership
            {
                return $this->seats["{$userId}|{$tenantId}"] ?? null;
            }
        };
    }

    private function audit(): AuditSink
    {
        return new class implements AuditSink {
            /** @var list<string> */ public array $actions = [];
            public function record(string $action, ?string $userId = null, ?string $tenantId = null, array $meta = [], ?string $ip = null): void { $this->actions[] = $action; }
        };
    }

    private function seat(string $userId, string $tenantId, string $role = 'member', MembershipStatus $s = MembershipStatus::Active): Membership
    {
        return Membership::of($userId, $tenantId, 'N', 'n', $role, $s, TenantStatus::Active);
    }

    public function test_issue_then_rotate_unscoped(): void
    {
        $store = $this->store();
        $svc = new RefreshTokenService($store, $this->auth(), $this->reader(), $this->audit(), accessTtl: 900);

        $issued = $svc->issue('u1');
        $rot = $svc->rotate($issued->token);

        $this->assertSame('access:u1::', $rot->accessToken);
        $this->assertSame(900, $rot->expiresIn);
        $this->assertSame('', $rot->tenantId);
        $this->assertNotSame($issued->token, $rot->refreshToken); // rotated

        // Old token is now revoked → reuse fails.
        $this->expectException(InvalidRefreshTokenException::class);
        $svc->rotate($issued->token);
    }

    public function test_rotate_scoped_includes_role_from_membership(): void
    {
        $store = $this->store();
        $svc = new RefreshTokenService(
            $store, $this->auth(),
            $this->reader(['u1|t1' => $this->seat('u1', 't1', 'admin')]),
            $this->audit(),
        );

        $issued = $svc->issue('u1', 't1');
        $rot = $svc->rotate($issued->token);

        $this->assertSame('access:u1:t1:admin', $rot->accessToken);
        $this->assertSame('t1', $rot->tenantId);
        $this->assertSame('admin', $rot->role);
    }

    public function test_rotate_scoped_denied_when_membership_revoked(): void
    {
        $store = $this->store();
        $svc = new RefreshTokenService(
            $store, $this->auth(),
            $this->reader(['u1|t1' => $this->seat('u1', 't1', 'member', MembershipStatus::Suspended)]),
            $audit = $this->audit(),
        );

        $issued = $svc->issue('u1', 't1');

        try {
            $svc->rotate($issued->token);
            $this->fail('Expected InvalidRefreshTokenException');
        } catch (InvalidRefreshTokenException) {
            // expected
        }
        $this->assertContains('auth.refresh_denied', $audit->actions);
    }

    public function test_reusing_a_rotated_token_revokes_the_whole_family(): void
    {
        $store = $this->store();
        $svc = new RefreshTokenService($store, $this->auth(), $this->reader(), $audit = $this->audit(), accessTtl: 900);

        $issued = $svc->issue('u1');
        $rot    = $svc->rotate($issued->token); // valid rotation → new token in same family

        // Replay the original (now-revoked) token → reuse detected.
        try {
            $svc->rotate($issued->token);
            $this->fail('Expected InvalidRefreshTokenException');
        } catch (InvalidRefreshTokenException) {
            // expected
        }
        $this->assertContains('auth.refresh_reuse_detected', $audit->actions);

        // The legitimate descendant token is now also dead (family burned).
        $this->expectException(InvalidRefreshTokenException::class);
        $svc->rotate($rot->refreshToken);
    }

    public function test_revoke_all_for_user(): void
    {
        $store = $this->store();
        $svc = new RefreshTokenService($store, $this->auth(), $this->reader(), $this->audit());

        $svc->issue('u1');
        $svc->issue('u1', 't1');
        $svc->issue('u2');

        $this->assertSame(2, $svc->revokeAllForUser('u1'));
    }
}
