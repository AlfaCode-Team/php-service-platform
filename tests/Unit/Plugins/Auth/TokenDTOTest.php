<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Auth\API\DTOs\TokenDTO;

#[CoversClass(TokenDTO::class)]
final class TokenDTOTest extends TestCase
{
    public function test_from_row_decodes_json_abilities(): void
    {
        $dto = TokenDTO::fromRow([
            'id'         => 'tok-1',
            'name'       => 'ci',
            'abilities'  => '["read","write"]',
            'expires_at' => null,
            'created_at' => '2026-01-01T00:00:00+00:00',
        ]);

        self::assertSame('tok-1', $dto->id);
        self::assertSame('ci', $dto->name);
        self::assertSame(['read', 'write'], $dto->abilities);
        self::assertNull($dto->expiresAt);
        self::assertInstanceOf(\DateTimeImmutable::class, $dto->createdAt);
    }

    public function test_from_row_accepts_array_abilities_and_filters_non_strings(): void
    {
        $dto = TokenDTO::fromRow(['id' => 't', 'abilities' => ['read', 42, 'write']]);

        self::assertSame(['read', 'write'], $dto->abilities);
        self::assertSame('default', $dto->name); // fallback
    }

    public function test_can_honours_wildcard_and_exact(): void
    {
        self::assertTrue(TokenDTO::fromRow(['id' => 't', 'abilities' => ['*']])->can('anything'));
        self::assertTrue(TokenDTO::fromRow(['id' => 't', 'abilities' => ['read']])->can('read'));
        self::assertFalse(TokenDTO::fromRow(['id' => 't', 'abilities' => ['read']])->can('write'));
    }

    public function test_is_expired_uses_supplied_clock(): void
    {
        $dto = TokenDTO::fromRow(['id' => 't', 'expires_at' => '2026-01-01T00:00:00+00:00']);

        self::assertTrue($dto->isExpired(new \DateTimeImmutable('2026-01-02')));
        self::assertFalse($dto->isExpired(new \DateTimeImmutable('2025-12-31')));
        // Non-expiring token is never expired.
        self::assertFalse(TokenDTO::fromRow(['id' => 't'])->isExpired(new \DateTimeImmutable()));
    }

    public function test_to_array_shape_is_stable(): void
    {
        $dto = TokenDTO::fromRow([
            'id'         => 'tok-1',
            'name'       => 'ci',
            'abilities'  => ['read'],
            'expires_at' => '2026-06-01T12:00:00+00:00',
        ]);

        $array = $dto->toArray();

        self::assertSame('tok-1', $array['id']);
        self::assertSame(['read'], $array['abilities']);
        self::assertSame('2026-06-01T12:00:00+00:00', $array['expires_at']);
        self::assertArrayHasKey('last_used_at', $array);
    }
}
