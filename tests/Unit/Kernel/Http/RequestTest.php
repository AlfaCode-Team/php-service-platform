<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class RequestTest extends TestCase
{
    public function test_method_is_upper_case_and_path_has_leading_slash(): void
    {
        $req = Request::create('/api/invoices?page=2', 'post');

        self::assertSame('POST', $req->method());
        self::assertSame('/api/invoices', $req->path());
    }

    public function test_input_merges_query_and_body(): void
    {
        $req = Request::create('/x?q=hello', 'POST', ['title' => 'Draft']);

        self::assertSame('hello', $req->input('q'));
        self::assertSame('Draft', $req->input('title'));
        self::assertSame('fallback', $req->input('missing', 'fallback'));
    }

    public function test_typed_accessors_cast_values(): void
    {
        $req = Request::create('/x', 'POST', ['active' => 'true', 'page' => '7']);

        self::assertTrue($req->boolean('active'));
        self::assertSame(7, $req->integer('page'));
        self::assertFalse($req->boolean('missing'));
        self::assertSame(0, $req->integer('missing'));
    }

    public function test_with_attribute_is_immutable(): void
    {
        $original = Request::create('/x', 'GET');
        $modified = $original->withAttribute('locale', 'fr');

        self::assertNotSame($original, $modified);
        self::assertNull($original->attribute('locale'));
        self::assertSame('fr', $modified->attribute('locale'));
    }

    public function test_with_identity_is_immutable_and_readable(): void
    {
        $original = Request::create('/x', 'GET');
        $withId   = $original->withIdentity(Identity::asUser('u42'));

        self::assertNotSame($original, $withId);
        self::assertNull($original->identity());
        self::assertSame('u42', $withId->identity()->userId);
    }
}
