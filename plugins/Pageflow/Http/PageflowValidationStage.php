<?php

declare(strict_types=1);

namespace Plugins\Pageflow\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;

/**
 * Native validation flow for Pageflow — the GDA answer to Inertia's manual
 * "share('errors', ...)" and the separate Precognition library.
 *
 * Wraps execution and translates a kernel ValidationException into the shape the
 * Pageflow client expects, so controllers just throw (via their DTOs) and never
 * wire errors by hand:
 *
 *   • Precognition request (Precognition: true) — return 422 { "errors": {...} }.
 *     usePrecognition()/precognitiveValidate() reads exactly this. No side
 *     effects run because a precognitive controller short-circuits BEFORE
 *     mutating (see pageflow_precognition()).
 *
 *   • Normal Pageflow submit — flash the errors to the session and 303-redirect
 *     back to the origin URL. The client follows the redirect, the origin page
 *     re-renders through the FULL pipeline (re-authorized, real props intact),
 *     and the `errors` shared prop surfaces them. This is the secure path: the
 *     error response is a normal authorized render, not a hand-built page.
 *
 *   • No session available — degrade to 422 { "errors": {...} } (manual handling).
 *
 * Non-Pageflow requests are re-thrown untouched for the kernel ErrorStage.
 */
final class PageflowValidationStage implements HttpStageContract
{
    /** Session key the errors are flashed under; read back by the errors sharer. */
    public const ERROR_FLASH_KEY = 'pageflow_errors';

    public function handle(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (ValidationException $e) {
            if (!$this->isPageflow($request)) {
                throw $e; // let the kernel ErrorStage render it (non-SPA client)
            }

            $errors = $this->flatten($e->errors);

            if ($this->isPrecognitive($request)) {
                return Response::json(['errors' => $errors], 422, [
                    'X-Pageflow'            => 'true',
                    'Precognition'          => 'true',
                    'Vary'                  => 'X-Pageflow, Precognition',
                ]);
            }

            $session = $this->session($request);
            if ($session !== null) {
                $bag = (string) ($request->header('X-Pageflow-Error-Bag') ?? '');
                $session->flash(self::ERROR_FLASH_KEY, $bag !== '' ? [$bag => $errors] : $errors);

                return Response::redirect($this->backUrl($request), 303);
            }

            // No session plugin — the client's useForm won't auto-populate, but a
            // manual onError handler still receives these. Session is recommended.
            return Response::json(['errors' => $errors], 422, ['X-Pageflow' => 'true']);
        }
    }

    private function isPageflow(Request $request): bool
    {
        return (string) ($request->header('X-Pageflow') ?? '') !== '';
    }

    private function isPrecognitive(Request $request): bool
    {
        return strtolower((string) ($request->header('Precognition') ?? '')) === 'true';
    }

    private function session(Request $request): ?SessionPort
    {
        $container = $request->container();
        if ($container !== null && $container->has(SessionPort::class)) {
            /** @var SessionPort $session */
            $session = $container->make(SessionPort::class);
            return $session;
        }
        return null;
    }

    /**
     * Prefer the referer; fall back to the client-declared URL, then the path.
     *
     * SECURITY: every candidate is reduced to a same-origin path (+query) — the
     * scheme/host are stripped — so the 303 Location can never point off-site.
     * This closes an open-redirect vector via a forged referer / X-Pageflow-Url.
     */
    private function backUrl(Request $request): string
    {
        $referer = $this->pathOnly((string) ($request->header('referer') ?? ''));
        if ($referer !== '') {
            return $referer;
        }

        $headerUrl = $this->pathOnly((string) ($request->header('X-Pageflow-Url') ?? ''));
        if ($headerUrl !== '') {
            return $headerUrl;
        }

        return $request->path();
    }

    /** Reduce any URL to a same-origin "/path?query" (never a scheme/host). */
    private function pathOnly(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }

        // Guard against protocol-relative ("//evil.com/x") and backslash tricks.
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');

        $query = parse_url($url, PHP_URL_QUERY);
        return is_string($query) && $query !== '' ? $path . '?' . $query : $path;
    }

    /**
     * Normalise ValidationException errors (string|string[]) to field => message.
     *
     * @param array<string, string|string[]> $errors
     * @return array<string, string>
     */
    private function flatten(array $errors): array
    {
        $out = [];
        foreach ($errors as $field => $message) {
            $out[(string) $field] = is_array($message)
                ? (string) ($message[0] ?? '')
                : (string) $message;
        }
        return $out;
    }
}
