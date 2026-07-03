<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Tenancy;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\FilterRegistry;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages\RouteFilterStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\SecurityFilters\Infrastructure\Http\Stages\RequireAuthStage;
use Plugins\Tenancy\Infrastructure\Http\Stages\RequireTenantStage;

/**
 * Pipeline-level test: exercises the REAL RouteFilterStage composing the REAL
 * `auth` + `tenant` filters exactly as the User feedback/settings routes declare
 * them ("filters": ["auth", "tenant"]). This is what unit tests of a single
 * stage cannot prove: that the onion order is correct (auth before tenant) and
 * that an authenticated-but-unscoped request is blocked before reaching the
 * handler.
 */
#[CoversClass(RouteFilterStage::class)]
final class RouteFilterCompositionTest extends TestCase
{
    private function stage(): RouteFilterStage
    {
        $registry = new FilterRegistry();
        $registry->register('auth', RequireAuthStage::class);
        $registry->register('tenant', RequireTenantStage::class);

        return new RouteFilterStage($registry, new CoreContainer());
    }

    /** A route declaring filters: ["auth", "tenant"], like /ajx/profile. */
    private function request(): Request
    {
        return Request::build(method: 'GET', path: '/ajx/profile')
            ->withAttribute('route_entry', ['filters' => ['auth', 'tenant']]);
    }

    private function next(): callable
    {
        return static fn(Request $r): Response => Response::json(['reached_handler' => true]);
    }

    public function test_guest_is_blocked_by_auth_before_tenant(): void
    {
        // No identity and no tenant: auth is outermost, so the result is 401
        // (auth), NOT 409 (tenant) — proves left-to-right onion ordering.
        $response = $this->stage()->handle($this->request(), $this->next());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_authenticated_without_tenant_is_blocked_by_tenant_guard(): void
    {
        $request  = $this->request()->withIdentity(Identity::asUser('user-A', ''));
        $response = $this->stage()->handle($request, $this->next());

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('tenant.required', (string) $response->getContent());
    }

    public function test_authenticated_with_active_tenant_reaches_handler(): void
    {
        $request = $this->request()
            ->withIdentity(Identity::asUser('user-A', 'tenant-1'))
            ->withAttribute('tenant', 'tenant-1');

        $response = $this->stage()->handle($request, $this->next());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('reached_handler', (string) $response->getContent());
    }
}
