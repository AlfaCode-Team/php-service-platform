<?php

declare(strict_types=1);

namespace Plugins\I18n\Infrastructure\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use Plugins\I18n\Support\Lang;
use Plugins\I18n\Translator;

/**
 * Resolves the per-request locale and exposes the Translator to the global
 * helpers for the duration of the request.
 *
 * Runs at after.load, once the route's module container exists. The active
 * locale is negotiated from Accept-Language against the app's supported list
 * (APP_LOCALES, comma-separated), falling back to the Translator's configured
 * default. The binding is always cleared in finally so it never leaks.
 */
final class LocaleStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $container = $request->container();
        if ($container === null || !$container->has(Translator::class)) {
            return $next($request);
        }

        /** @var Translator $translator */
        $translator = $container->make(Translator::class);

        $locale = $this->negotiate($request, $translator);
        if ($locale !== null) {
            $translator->setLocale($locale);
        }

        Lang::bind($translator);
        try {
            return $next($request);
        } finally {
            Lang::clear();
        }
    }

    private function negotiate(Request $request, Translator $translator): ?string
    {
        $supported = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) (env('APP_LOCALES') ?: $translator->locale())),
        )));

        if ($supported === []) {
            return null;
        }

        return $request->negotiate()->language($supported, $translator->locale());
    }
}
