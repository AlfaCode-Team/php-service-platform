<?php

declare(strict_types=1);

namespace Plugins\Crypto;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use Plugins\Crypto\Infrastructure\AesEncrypter;
use Plugins\Crypto\Infrastructure\PasswordHasher;

/**
 * Crypto plugin — provides the EncryptionPort (AES-256-GCM) and HashingPort
 * (bcrypt/argon2) adapters the kernel defines but does not implement.
 *
 * The adapters are wired as app-lifetime ports in the project bootstrap
 * (->withPorts([...])). This Provider additionally rebinds them into the
 * request-scoped container so module services can inject the ports directly.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'crypto.services';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [EncryptionPort::class, HashingPort::class];
    }

    public function register(ModuleContainer $container): void
    {
        // Fallback bindings so the ports resolve even if a project forgot to
        // wire them in withPorts(). Reads keys/cost from env.
        if (!$container->has(HashingPort::class)) {
            $container->bind(HashingPort::class, static fn() => new PasswordHasher(
                cost: (int) (env('HASH_BCRYPT_COST') ?: 12),
            ));
        }
        if (!$container->has(EncryptionPort::class)) {
            $container->bind(EncryptionPort::class, static function () {
                $keys = array_values(array_filter([
                    env('APP_KEY') ?: '',
                    env('APP_KEY_PREVIOUS') ?: '',
                ], static fn(string $k) => $k !== ''));
                return new AesEncrypter($keys === [] ? str_repeat('0', 32) : $keys);
            });
        }
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
    }
}
