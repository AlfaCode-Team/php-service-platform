<?php

declare(strict_types=1);

namespace Plugins\Pageflow\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;

/**
 * Server-Sent-Events channel for Pageflow reactive props.
 *
 * SECURITY MODEL (do not weaken):
 *   This channel emits ONLY "these prop keys are stale" signals — never prop
 *   data. The client reacts with a normal authenticated partial reload, so all
 *   data still flows through the kernel pipeline (SecurityStage, filters,
 *   authorization, tenant routing). A forged/hijacked stream therefore leaks
 *   nothing: it can at most ask a client to re-fetch data it may already see.
 *
 *   The stream itself is authenticated by the session cookie (EventSource with
 *   credentials); we refuse guests. The $staleKeys resolver is passed the
 *   Request so it can scope to identity/tenant — YOUR resolver must only report
 *   keys for resources this identity is allowed to observe.
 *
 * RUNTIME: a long-lived stream holds one worker for its lifetime. Use it under
 *   OpenSwoole (or a dedicated SSE worker pool), not on a small PHP-FPM pool.
 *
 * Wire format:
 *   event: stale\n data: {"keys":["orders"]}\n\n
 *   event: ping\n  data: {}\n\n              (keep-alive)
 *
 * Usage from a project controller (route: GET /pageflow/stream):
 *   return PageflowStream::open($request, function (Request $r): array {
 *       // return the stale prop keys for THIS identity/tenant since last tick
 *       return $this->dashboard->drainStaleKeys($r->identity());
 *   });
 */
final class PageflowStream
{
    /**
     * @param callable(Request):(array<int,string>|array{keys:array<int,string>,id?:string}) $staleKeys
     *        resolver returning the stale prop keys since the previous tick
     *        (scoped to the request's identity/tenant). Return [] when nothing
     *        changed. May instead return {keys, id} to drive the SSE `id:` cursor
     *        so reconnecting clients resume via Last-Event-ID.
     * @param int $intervalMs poll cadence in milliseconds (min 250).
     * @param int $maxSeconds hard lifetime cap; 0 = until the client disconnects.
     */
    public static function open(
        Request $request,
        callable $staleKeys,
        int $intervalMs = 2000,
        int $maxSeconds = 0,
    ): Response {
        // Fail closed: an unauthenticated principal gets no channel.
        $identity = $request->identity();
        if ($identity === null || $identity->isGuest()) {
            return Response::json(
                ['error' => ['code' => 'pageflow.stream.unauthenticated', 'message' => 'Authentication required.']],
                401,
            );
        }

        $interval = max(250, $intervalMs) * 1000; // → microseconds
        $deadline = $maxSeconds > 0 ? microtime(true) + $maxSeconds : 0.0;

        $emit = static function (callable $stale) use ($request, $interval, $deadline): void {
            self::disableOutputBuffering();

            // Initial comment forces proxies to open the stream immediately.
            echo ": pageflow stream open\n\n";
            self::flush();

            while (true) {
                if (self::clientGone()) {
                    return;
                }

                $result = $stale($request);

                // Resolver may return a plain key list, or {keys, id} to drive
                // the SSE cursor for reconnect-safe resume via Last-Event-ID.
                $rawKeys = \is_array($result) && \array_key_exists('keys', $result)
                    ? $result['keys']
                    : $result;
                $eventId = \is_array($result) && isset($result['id']) ? (string) $result['id'] : null;

                $keys = array_values(array_filter(
                    (array) $rawKeys,
                    static fn($k): bool => \is_string($k) && $k !== '',
                ));

                if ($eventId !== null) {
                    echo 'id: ' . str_replace(["\r", "\n"], '', $eventId) . "\n";
                }

                if ($keys !== []) {
                    echo "event: stale\n";
                    echo 'data: ' . json_encode(
                        ['keys' => $keys],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    ) . "\n\n";
                } else {
                    // Keep-alive so intermediaries don't drop an idle connection.
                    echo "event: ping\n";
                    echo "data: {}\n\n";
                }
                self::flush();

                if ($deadline > 0.0 && microtime(true) >= $deadline) {
                    return;
                }

                self::sleep($interval);
            }
        };

        return Response::stream(static fn() => $emit($staleKeys), 200, [
            'Content-Type'      => 'text/event-stream; charset=UTF-8',
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
            // Disable nginx proxy buffering so events flush in real time.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private static function disableOutputBuffering(): void
    {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ini_set('zlib.output_compression', '0');
    }

    private static function flush(): void
    {
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        @flush();
    }

    private static function clientGone(): bool
    {
        // Under FPM connection_aborted() reflects the client; Swoole ignores it
        // and relies on the deadline / its own connection lifecycle.
        return function_exists('connection_aborted') && connection_aborted() === 1;
    }

    /** Coroutine-safe sleep under OpenSwoole; plain usleep otherwise. */
    private static function sleep(int $microseconds): void
    {
        if (class_exists('\OpenSwoole\Coroutine') && \method_exists('\OpenSwoole\Coroutine', 'usleep')) {
            \OpenSwoole\Coroutine::usleep($microseconds);
            return;
        }
        if (class_exists('\Swoole\Coroutine') && \method_exists('\Swoole\Coroutine', 'usleep')) {
            \Swoole\Coroutine::usleep($microseconds);
            return;
        }
        usleep($microseconds);
    }
}
