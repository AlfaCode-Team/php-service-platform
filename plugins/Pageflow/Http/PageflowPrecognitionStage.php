<?php

declare(strict_types=1);

namespace Plugins\Pageflow\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

/**
 * Marks precognition ("validate only, no side effects") requests so the rest of
 * the stack can defend against accidental writes.
 *
 * Precognition is COOPERATIVE: a controller is expected to short-circuit via
 * pageflow_precognition($request) before mutating. This stage adds defence in
 * depth by exposing the intent as a request attribute, so a Service/Repository
 * can assert-read-only when it sees it:
 *
 *   if ($request->attribute('precognition') === true) { ...refuse writes... }
 *
 * It intentionally does NOT auto-wrap a DB transaction: the platform's Services
 * manage their own (possibly committing) transactions, and a blanket outer
 * rollback could conflict or silently swallow a real commit. The reliable guard
 * remains the controller short-circuit; this attribute makes that intent visible
 * to every layer.
 */
final class PageflowPrecognitionStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        if (strtolower((string) ($request->header('Precognition') ?? '')) !== 'true') {
            return $next($request);
        }

        $fields = $this->fields($request);

        // We deliberately do NOT open an outer DB transaction here: the platform's
        // Services manage their own (nesting an outer tx would make their
        // beginTransaction() throw "already in transaction"). Instead we expose
        // the intent as attributes so a Repository/Service can assert read-only
        // when it sees them. Enforcement stays where it can be correct.
        $rollback = (bool) (env('PAGEFLOW_PRECOGNITION_ROLLBACK') ?: false);

        $request = $request
            ->withAttribute('precognition', true)
            ->withAttribute('precognition_fields', $fields)
            ->withAttribute('precognition_rollback', $rollback);

        return $next($request);
    }

    /** @return list<string> */
    private function fields(Request $request): array
    {
        $raw = (string) ($request->header('Precognition-Validate-Only') ?? '');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn(string $s): bool => $s !== '',
        ));
    }
}
