<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Security;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\SecurityVerdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecurityVerdict::class)]
final class SecurityVerdictTest extends TestCase
{
    public function test_deny_carries_status_and_reason_and_no_identity(): void
    {
        $v = SecurityVerdict::deny(403, 'blocked by firewall');

        self::assertTrue($v->isDenied());
        self::assertFalse($v->isAllowed());
        self::assertSame(403, $v->statusCode());
        self::assertSame('blocked by firewall', $v->reason());
        self::assertNull($v->identity());
    }

    public function test_allow_takes_identity_from_the_request(): void
    {
        $request = Request::create('/whoami', 'GET')->withIdentity(Identity::asUser('u1'));

        $v = SecurityVerdict::allow($request);

        self::assertTrue($v->isAllowed());
        self::assertFalse($v->isDenied());
        self::assertSame(200, $v->statusCode());
        self::assertNotNull($v->identity());
        self::assertSame('u1', $v->identity()->userId);
    }

    public function test_allow_without_identity_yields_null_identity(): void
    {
        $v = SecurityVerdict::allow(Request::create('/', 'GET'));

        self::assertTrue($v->isAllowed());
        self::assertNull($v->identity());
    }
}
