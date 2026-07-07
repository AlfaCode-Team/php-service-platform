<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Tenancy;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Tenancy\Infrastructure\Http\Stages\RequireTenantStage;

#[CoversClass(RequireTenantStage::class)]
final class RequireTenantStageTest extends TestCase
{
    private function next(): callable
    {
        return static fn(Request $r): Response => Response::json(['ok' => true]);
    }

    public function test_passes_through_when_tenant_is_active(): void
    {
        $request  = Request::build(method: 'GET', path: '/ajx/profile')->withAttribute('tenant', 'tenant-1');
        $response = (new RequireTenantStage())->handle($request, $this->next());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_blocks_with_409_when_no_tenant(): void
    {
        $request  = Request::build(method: 'GET', path: '/ajx/profile');
        $response = (new RequireTenantStage())->handle($request, $this->next());

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('tenant.required', (string) $response->getContent());
    }

    public function test_blocks_when_tenant_is_empty_string(): void
    {
        $request  = Request::build(method: 'GET', path: '/ajx/profile')->withAttribute('tenant', '');
        $response = (new RequireTenantStage())->handle($request, $this->next());

        $this->assertSame(409, $response->getStatusCode());
    }
}
