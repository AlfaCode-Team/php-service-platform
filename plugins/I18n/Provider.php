<?php

declare(strict_types=1);

namespace Plugins\I18n;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;

/**
 * I18n plugin — file-based translator for localized messages (validation, etc.).
 *
 * Binds a shared Translator into the request-scoped container so services can
 * inject it. Lang files live under plugins/I18n/lang/{locale}/{group}.php;
 * projects can point APP_LANG_PATH at their own directory to override/extend.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'i18n.translation';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [Translator::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bind(Translator::class, static function () {
            $dir = env('APP_LANG_PATH') ?: (__DIR__ . '/lang');
            return new Translator(
                directory: $dir,
                locale:    env('APP_LOCALE') ?: 'en',
                fallback:  env('APP_FALLBACK_LOCALE') ?: 'en',
            );
        });
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
    }
}
