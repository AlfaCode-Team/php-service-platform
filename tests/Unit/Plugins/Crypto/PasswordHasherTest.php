<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Crypto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Crypto\Infrastructure\PasswordHasher;

#[CoversClass(PasswordHasher::class)]
final class PasswordHasherTest extends TestCase
{
    public function test_make_then_check_succeeds(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->make('s3cret');

        $this->assertNotSame('s3cret', $hash);
        $this->assertTrue($hasher->check('s3cret', $hash));
    }

    public function test_check_fails_for_wrong_password(): void
    {
        $hasher = new PasswordHasher();

        $this->assertFalse($hasher->check('wrong', $hasher->make('s3cret')));
    }

    public function test_check_fails_for_empty_hash(): void
    {
        $this->assertFalse((new PasswordHasher())->check('x', ''));
    }

    public function test_needs_rehash_when_cost_increases(): void
    {
        $low = new PasswordHasher(cost: 4);
        $hash = $low->make('s3cret');
        $high = new PasswordHasher(cost: 12);

        $this->assertTrue($high->needsRehash($hash));
        $this->assertFalse($low->needsRehash($hash));
    }
}
