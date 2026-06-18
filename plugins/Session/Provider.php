<?php

declare(strict_types=1);

namespace Plugins\Session;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use Plugins\Session\Infrastructure\Handlers\ArraySessionHandler;
use Plugins\Session\Infrastructure\Handlers\CookieSessionConfig;
use Plugins\Session\Infrastructure\Handlers\CookieSessionHandler;
use Plugins\Session\Infrastructure\Handlers\FileSessionHandler;
use Plugins\Session\Infrastructure\Http\StartSessionStage;
use Plugins\Session\Infrastructure\Store;

/**
 * Session plugin — provides the kernel SessionPort and drives its lifecycle.
 *
 * The Store is bound as a per-request singleton so StartSessionStage and any
 * module controller resolve the SAME instance for the request. Driver selection
 * (file | array) is env-driven; file uses the project var/ path via Paths.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'session.management';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [SessionPort::class];
    }

    public function register(ModuleContainer $container): void
    {
        if ($container->has(SessionPort::class)) {
            return; // a project already provided SessionPort
        }

        $container->singleton(SessionPort::class, static function (ModuleContainer $c): SessionPort {
            $lifetime = (int) (env('SESSION_LIFETIME') ?: 7200);
            $driver   = strtolower((string) (env('SESSION_DRIVER') ?: 'file'));

            $handler = match ($driver) {
                'array'  => new ArraySessionHandler($lifetime),
                'cookie' => new CookieSessionHandler(self::cookieConfig($c, $lifetime)),
                default  => new FileSessionHandler(
                    path:     env('SESSION_PATH') ?: Paths::var('sessions'),
                    lifetime: $lifetime,
                ),
            };

            return new Store(
                name:          (string) (env('SESSION_COOKIE') ?: 'hkm_session'),
                handler:       $handler,
                serialization: (string) (env('SESSION_SERIALIZATION') ?: 'json'),
            );
        });
    }

    /** Translate env → cookie-driver security policy. */
    private static function cookieConfig(ModuleContainer $c, int $lifetime): CookieSessionConfig
    {
        return new CookieSessionConfig(
            encrypter:             self::encrypter($c),
            signingKey:            (string) (env('SESSION_SIGNING_KEY') ?: env('APP_KEY') ?: ''),
            lifetime:              $lifetime,
            idleTimeout:           (int) (env('SESSION_IDLE_TIMEOUT') ?: 0),
            compressThreshold:     (int) (env('SESSION_COOKIE_COMPRESS') ?: 1024),
            maxBytes:              (int) (env('SESSION_COOKIE_MAX_BYTES') ?: 3800),
            requireAuthentication: self::flag('SESSION_COOKIE_REQUIRE_AUTH', true),
            requireEncryption:     self::flag('SESSION_COOKIE_REQUIRE_ENCRYPTION', false),
            fingerprint:           self::fingerprintParts(env('SESSION_COOKIE_FINGERPRINT')),
        );
    }

    /**
     * Parse SESSION_COOKIE_FINGERPRINT into fingerprint components.
     *
     *   off | false | 0 | "" | null  → []            (disabled — default)
     *   ua                            → [ua]          (safe: survives IP changes)
     *   ip                            → [ip]
     *   ua,ip | all | strict | true   → [ua, ip]      (strongest)
     *
     * @return list<string>
     */
    private static function fingerprintParts(mixed $value): array
    {
        $raw = strtolower(trim((string) ($value ?? '')));

        return match ($raw) {
            '', 'off', 'false', '0', 'no'  => [],
            'true', 'on', '1', 'yes',
            'all', 'strict', 'ua,ip', 'ip,ua' => [CookieSessionConfig::FP_USER_AGENT, CookieSessionConfig::FP_IP],
            CookieSessionConfig::FP_USER_AGENT => [CookieSessionConfig::FP_USER_AGENT],
            CookieSessionConfig::FP_IP         => [CookieSessionConfig::FP_IP],
            default => array_values(array_filter(
                array_map('trim', explode(',', $raw)),
                static fn (string $p): bool => in_array($p, [CookieSessionConfig::FP_USER_AGENT, CookieSessionConfig::FP_IP], true),
            )),
        };
    }

    /** The bound EncryptionPort (used to secure the cookie driver), or null. */
    private static function encrypter(ModuleContainer $c): ?EncryptionPort
    {
        if (!$c->has(EncryptionPort::class)) {
            return null;
        }

        $encrypter = $c->make(EncryptionPort::class);

        return $encrypter instanceof EncryptionPort ? $encrypter : null;
    }

    /** Parse a truthy/falsey env flag with a default. */
    private static function flag(string $key, bool $default): bool
    {
        $value = env($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Open/persist the session around the request, after the container exists.
        $http->hook('after.load', StartSessionStage::class, priority: 20);
    }
}
