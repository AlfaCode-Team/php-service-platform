<?php

declare(strict_types=1);

namespace Plugins\Pageflow\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;

/**
 * Ready-made Pageflow endpoints so a project gets realtime + CSRF refresh with
 * zero boilerplate. Registered in module.json:
 *
 *   GET /pageflow/csrf     -> a fresh CSRF token for a long-lived SPA
 *   GET /pageflow/stream   -> the reactive SSE channel (auth-gated)
 *
 * SECURITY:
 *   • /pageflow/stream is scoped to the caller's TENANT — the client picks only a
 *     topic (?channel=dashboard); the server prefixes it with the tenant so one
 *     tenant can never watch another's channel. Emitters touch the SAME composed
 *     name: $channel->touch("t:{$tenantId}:dashboard", ['orders']). For per-user
 *     topics, include the userId in the topic on both ends.
 *   • Only key NAMES flow over the stream — never data — so even a forged
 *     subscription leaks nothing (the client re-fetches through the pipeline).
 */
final class PageflowEndpointsController
{
    public function __construct(
        private readonly PageflowResponder $responder,
        private readonly PageflowChannel $channel,
    ) {
    }

    /** GET /pageflow/csrf — refresh an expired token without a full reload. */
    public function csrf(Request $request): Response
    {
        return $this->responder->csrfResponse($request);
    }

    /** GET /pageflow/stream — tenant-scoped reactive channel (SSE). */
    public function stream(Request $request): Response
    {
        $identity = $request->identity();
        $tenant   = $identity !== null && $identity->tenantId !== '' ? $identity->tenantId : 'central';

        $topic = preg_replace('/[^A-Za-z0-9_\-.]/', '', (string) ($request->query('channel') ?? 'default'));
        $topic = $topic !== '' ? $topic : 'default';

        $channel  = "t:{$tenant}:{$topic}";
        $interval = (int) (env('PAGEFLOW_STREAM_INTERVAL') ?: 2000);
        // Bounded lifetime (DoS guard) — the client transparently reconnects.
        $maxSeconds = (int) (env('PAGEFLOW_STREAM_MAX_SECONDS') ?: 300);

        return $this->channel->stream($request, $channel, $interval, $maxSeconds);
    }
}
