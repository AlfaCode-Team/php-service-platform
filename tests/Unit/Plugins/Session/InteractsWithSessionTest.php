<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Session;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Contracts\RequestAware;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Plugins\Session\Infrastructure\Store;
use Plugins\Session\Provider as SessionProvider;
use Project\Http\Controllers\Concerns\InteractsWithSession;

/**
 * End-to-end check of InteractsWithSession against the REAL Session plugin
 * (Provider wiring driven by env/config) and the actual Store adapter.
 */
#[CoversNothing]
final class InteractsWithSessionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        // Snapshot then drive config purely through env (the array driver keeps
        // the test hermetic — no filesystem session files).
        foreach (['SESSION_DRIVER', 'SESSION_COOKIE', 'SESSION_LIFETIME', 'SESSION_SERIALIZATION'] as $k) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
        }
        $_ENV['SESSION_DRIVER']        = 'array';
        $_ENV['SESSION_COOKIE']        = 'test_session';
        $_ENV['SESSION_LIFETIME']      = '1800';
        $_ENV['SESSION_SERIALIZATION'] = 'json';
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }

    /** Build a request-scoped container with SessionPort wired by the real Provider. */
    private function makeContainer(): ModuleContainer
    {
        $container = new ModuleContainer(new CoreContainer());
        $container->setScope('session.management');
        (new SessionProvider())->register($container);

        return $container;
    }

    /** A concrete controller exercising the trait (mirrors a base controller). */
    private function makeController(ModuleContainer $container): object
    {
        $controller = new class implements RequestAware {
            use InteractsWithSession;

            public function setRequest(Request $request): static
            {
                $this->request = $request;
                return $this;
            }

            public function call(string $method, ...$args): mixed
            {
                return $this->{$method}(...$args);
            }
        };

        $request = Request::build('GET', '/')->withContainer($container);
        $controller->setRequest($request);

        return $controller;
    }

    public function test_config_and_env_drive_the_provider_wiring(): void
    {
        $session = $this->makeContainer()->make(SessionPort::class);

        $this->assertInstanceOf(SessionPort::class, $session);
        $this->assertInstanceOf(Store::class, $session);
        // SESSION_COOKIE env flows through to the Store's name.
        $this->assertSame('test_session', $session->name());
    }

    public function test_put_get_has_forget_round_trip_through_the_trait(): void
    {
        $container = $this->makeContainer();
        $container->make(SessionPort::class)->start(); // open as StartSessionStage would
        $c = $this->makeController($container);

        $c->call('sessionPut', 'user_id', 42);
        $this->assertTrue($c->call('sessionHas', 'user_id'));
        $this->assertSame(42, $c->call('sessionGet', 'user_id'));

        $c->call('sessionForget', 'user_id');
        $this->assertFalse($c->call('sessionHas', 'user_id'));
        $this->assertSame('fallback', $c->call('sessionGet', 'missing', 'fallback'));
    }

    public function test_pull_reads_and_removes(): void
    {
        $container = $this->makeContainer();
        $container->make(SessionPort::class)->start();
        $c = $this->makeController($container);

        $c->call('sessionPut', 'flashy', 'once');
        $this->assertSame('once', $c->call('sessionPull', 'flashy'));
        $this->assertFalse($c->call('sessionHas', 'flashy'));
    }

    public function test_flash_and_csrf_token(): void
    {
        $container = $this->makeContainer();
        $container->make(SessionPort::class)->start();
        $c = $this->makeController($container);

        $c->call('flash', 'status', 'Saved!');
        $this->assertSame('Saved!', $c->call('sessionGet', 'status'));

        $token = $c->call('csrfToken');
        $this->assertIsString($token);
        $this->assertSame(40, strlen($token)); // 20 random bytes hex-encoded
    }

    public function test_regenerate_keeps_data_invalidate_wipes_it(): void
    {
        $container = $this->makeContainer();
        $session   = $container->make(SessionPort::class);
        $session->start();
        $c = $this->makeController($container);

        $c->call('sessionPut', 'k', 'v');
        $idBefore = $session->id();

        $c->call('regenerateSession');
        $this->assertNotSame($idBefore, $session->id());
        $this->assertSame('v', $c->call('sessionGet', 'k')); // data survives

        $c->call('invalidateSession');
        $this->assertFalse($c->call('sessionHas', 'k')); // data wiped
    }

    public function test_helpers_are_safe_no_ops_when_plugin_absent(): void
    {
        // Container with NO SessionPort bound — read helpers fall back, writes no-op.
        $container = new ModuleContainer(new CoreContainer());
        $c = $this->makeController($container);

        $this->assertNull($c->call('session'));
        $this->assertSame('def', $c->call('sessionGet', 'x', 'def'));
        $this->assertFalse($c->call('sessionHas', 'x'));
        $this->assertSame('', $c->call('csrfToken'));

        // Must not throw.
        $c->call('sessionPut', 'x', 1);
        $c->call('flash', 'x', 1);
        $this->assertTrue(true);
    }
}
