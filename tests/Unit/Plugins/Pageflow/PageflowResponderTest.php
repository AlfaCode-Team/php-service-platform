<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Pageflow;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Pageflow\Http\PageflowPage;
use Plugins\Pageflow\Http\PageflowResponder;
use Plugins\Pageflow\Http\PageflowVersionStage;

#[CoversClass(PageflowResponder::class)]
#[CoversClass(PageflowPage::class)]
#[CoversClass(PageflowVersionStage::class)]
final class PageflowResponderTest extends TestCase
{
    private function responder(): PageflowResponder
    {
        return new PageflowResponder(version: 'v1', rootView: '<html><body>{{app}}</body></html>', appId: 'app');
    }

    /** @param array<string,string> $headers */
    private function request(array $headers = [], string $method = 'GET', string $path = '/users'): Request
    {
        return Request::build(method: $method, path: $path, headers: $headers);
    }

    public function test_full_load_returns_html_shell_with_data_page(): void
    {
        $body = $this->responder()->render($this->request(), 'Users/Index', ['users' => [['id' => 1]]])->body();

        $this->assertStringContainsString('data-page=', $body);
        $this->assertStringContainsString('Users/Index', $body);
        $this->assertStringContainsString('<html>', $body);
    }

    public function test_xhr_request_returns_json_page_object(): void
    {
        $response = $this->responder()->render($this->request(['X-Pageflow' => 'true']), 'Users/Index', ['users' => [1, 2]]);
        $page = json_decode($response->body(), true);

        $this->assertSame('Users/Index', $page['component']);
        $this->assertSame('/users', $page['url']);
        $this->assertSame('v1', $page['version']);
        $this->assertSame('true', $response->headers()['X-Pageflow'] ?? null);
    }

    public function test_partial_reload_returns_only_requested_props(): void
    {
        $response = $this->responder()->render(
            $this->request([
                'X-Pageflow' => 'true',
                'X-Pageflow-Partial-Data' => 'a,b',
                'X-Pageflow-Partial-Component' => 'Users/Index',
            ]),
            'Users/Index',
            ['a' => 1, 'b' => 2, 'c' => 3],
        );

        $this->assertSame(['a' => 1, 'b' => 2], (array) json_decode($response->body(), true)['props']);
    }

    public function test_partial_reload_except_drops_named_props(): void
    {
        $response = $this->responder()->render(
            $this->request([
                'X-Pageflow' => 'true',
                'X-Pageflow-Partial-Except' => 'c',
                'X-Pageflow-Partial-Component' => 'Users/Index',
            ]),
            'Users/Index',
            ['a' => 1, 'b' => 2, 'c' => 3],
        );

        $this->assertSame(['a' => 1, 'b' => 2], (array) json_decode($response->body(), true)['props']);
    }

    public function test_partial_rules_ignored_when_component_differs(): void
    {
        $response = $this->responder()->render(
            $this->request([
                'X-Pageflow' => 'true',
                'X-Pageflow-Partial-Data' => 'a',
                'X-Pageflow-Partial-Component' => 'Other',
            ]),
            'Users/Index',
            ['a' => 1, 'b' => 2],
        );

        $this->assertSame(['a' => 1, 'b' => 2], (array) json_decode($response->body(), true)['props']);
    }

    public function test_version_stage_returns_409_for_stale_client_version(): void
    {
        putenv('PAGEFLOW_VERSION=v2');
        $stage = new PageflowVersionStage();
        $next = static fn(Request $r) => \AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response::text('OK');

        $response = $stage->handle(
            $this->request(['X-Pageflow' => 'true', 'X-Pageflow-Version' => 'v1']),
            $next,
        );

        $this->assertSame(409, $response->status());
        $this->assertSame('/users', $response->headers()['X-Pageflow-Location'] ?? null);
        putenv('PAGEFLOW_VERSION');
    }

    public function test_version_stage_passes_matching_version_through(): void
    {
        putenv('PAGEFLOW_VERSION=v2');
        $stage = new PageflowVersionStage();
        $next = static fn(Request $r) => \AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response::text('OK');

        $response = $stage->handle(
            $this->request(['X-Pageflow' => 'true', 'X-Pageflow-Version' => 'v2']),
            $next,
        );

        $this->assertSame('OK', $response->body());
        putenv('PAGEFLOW_VERSION');
    }
}
