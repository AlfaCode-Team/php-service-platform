<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Auth\Application\Ports\RefreshTokenStore;
use Plugins\Auth\Application\Services\RefreshTokenService;
use Plugins\Auth\Domain\Entities\RefreshTokenRecord;
use Plugins\Auth\Domain\Exceptions\InvalidRefreshTokenException;
use Tests\Unit\Plugins\Auth\Support\FakeAuthService;

#[CoversClass(RefreshTokenService::class)]
final class RefreshTokenServiceTest extends TestCase
{
    private function store(): RefreshTokenStore
    {
        return new class implements RefreshTokenStore {
            /** @var array<string, array<string, mixed>> keyed by token_id */
            public array $rows = [];
            public function store(string $tokenId, string $familyId, string $userId, string $tokenHash, ?string $tenantId, ?string $device, ?string $ip, \DateTimeImmutable $expiresAt): void
            {
                $this->rows[$tokenId] = ['token_id' => $tokenId, 'family_id' => $familyId, 'user_id' => $userId, 'hash' => $tokenHash, 'tenant_id' => $tenantId, 'revoked' => false, 'exp' => $expiresAt];
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
            public function revoke(string $tokenId): void { $this->revokeIfActive($tokenId); }
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

    private function service(RefreshTokenStore $store): RefreshTokenService
    {
        return new RefreshTokenService($store, new FakeAuthService(), accessTtl: 900);
    }

    public function test_issue_then_rotate_returns_new_pair_and_revokes_old(): void
    {
        $store = $this->store();
        $svc   = $this->service($store);

        $issued = $svc->issue('u1');
        $rot    = $svc->rotate($issued->token);

        self::assertNotSame($issued->token, $rot->refreshToken); // rotated
        self::assertSame(900, $rot->expiresIn);
        self::assertNotEmpty($rot->accessToken);
        // The originally-issued token is now revoked (one-time use).
        self::assertNull($store->findActiveByHash(hash('sha256', $issued->token)));
    }

    public function test_rotate_unknown_token_throws(): void
    {
        $this->expectException(InvalidRefreshTokenException::class);
        $this->service($this->store())->rotate('not-a-real-token');
    }

    public function test_tenant_scope_passes_through_without_a_membership_check(): void
    {
        // No MembershipReader anymore — the tenantId simply rides through.
        $store  = $this->store();
        $svc    = $this->service($store);
        $issued = $svc->issue('u1', 'tenant-9');

        $rot = $svc->rotate($issued->token);
        self::assertSame('tenant-9', $rot->tenantId);
    }

    public function test_reusing_a_rotated_token_revokes_the_whole_family(): void
    {
        $store  = $this->store();
        $svc    = $this->service($store);
        $issued = $svc->issue('u1');

        $rot = $svc->rotate($issued->token);        // old token now revoked
        try {
            $svc->rotate($issued->token);           // replay → reuse detected
            $this->fail('Expected InvalidRefreshTokenException');
        } catch (InvalidRefreshTokenException) {
        }

        // The descendant issued by the first rotation is also dead now.
        $this->expectException(InvalidRefreshTokenException::class);
        $svc->rotate($rot->refreshToken);
    }

    public function test_revoke_and_revoke_all(): void
    {
        $store = $this->store();
        $svc   = $this->service($store);
        $a = $svc->issue('u1');
        $b = $svc->issue('u1');

        $svc->revoke($a->token);
        self::assertNull($store->findActiveByHash(hash('sha256', $a->token)));

        self::assertSame(1, $svc->revokeAllForUser('u1')); // only b remained active
    }
}
