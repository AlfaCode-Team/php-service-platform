<?php

declare(strict_types=1);

namespace Plugins\Cookie;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;
use Plugins\Cookie\Infrastructure\CookieJar;
use Plugins\Cookie\Infrastructure\Http\QueuedCookiesStage;

/**
 * Cookie plugin — request-scoped CookieJar + a stage that flushes queued cookies
 * onto the response with optional value encryption.
 *
 * The jar is a per-request singleton (one instance shared by every module and
 * the flush stage). It uses the kernel EncryptionPort when one is bound, so
 * cookie values are encrypted with the same key rotation as the rest of the app.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'http.cookies';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [CookieJar::class];
    }

    public function register(ModuleContainer $container): void
    {
        if ($container->has(CookieJar::class)) {
            return;
        }

        $container->singleton(CookieJar::class, static function (ModuleContainer $c): CookieJar {
            $encrypter = $c->has(EncryptionPort::class) ? $c->make(EncryptionPort::class) : null;

            $exemptRaw = (string) (env('COOKIE_ENCRYPT_EXEMPT') ?: '');
            $exempt    = $exemptRaw === ''
                ? []
                : array_values(array_filter(array_map('trim', explode(',', $exemptRaw))));

            return new CookieJar(
                encrypter: $encrypter instanceof EncryptionPort ? $encrypter : null,
                exempt:    $exempt,
            );
        });
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Flush queued cookies just before security headers decorate the response.
        $http->hook('after.load', QueuedCookiesStage::class, priority: 25);
    }
}
