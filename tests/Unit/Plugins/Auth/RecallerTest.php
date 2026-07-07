<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Auth\Domain\ValueObjects\Recaller;

#[CoversClass(Recaller::class)]
final class RecallerTest extends TestCase
{
    public function test_make_composes_and_parses_round_trip(): void
    {
        $recaller = Recaller::make('user-1', 'secret-token');

        self::assertSame('user-1|secret-token', $recaller->value());
        self::assertSame('user-1', $recaller->id());
        self::assertSame('secret-token', $recaller->token());
        self::assertTrue($recaller->valid());
    }

    public function test_token_may_itself_contain_a_pipe(): void
    {
        // explode limit of 2 keeps any pipe in the token segment intact.
        $recaller = new Recaller('user-1|a|b|c');

        self::assertSame('user-1', $recaller->id());
        self::assertSame('a|b|c', $recaller->token());
        self::assertTrue($recaller->valid());
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_are_rejected(string $raw): void
    {
        self::assertFalse((new Recaller($raw))->valid());
    }

    /** @return iterable<string, array{0:string}> */
    public static function invalidValues(): iterable
    {
        yield 'empty'            => [''];
        yield 'no delimiter'     => ['justanid'];
        yield 'missing token'    => ['user-1|'];
        yield 'missing id'       => ['|token'];
        yield 'blank both'       => [' | '];
    }
}
