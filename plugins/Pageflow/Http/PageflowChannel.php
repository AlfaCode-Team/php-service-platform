<?php

declare(strict_types=1);

namespace Plugins\Pageflow\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;

/**
 * Emit side of Pageflow reactive props — the piece that makes realtime a
 * one-liner instead of a poller.
 *
 * A writer (a Service, after commit; or an EventBus listener) calls:
 *   $channel->touch("dashboard:{$tenantId}", ['orders', 'stats']);
 * which bumps a monotonically-increasing version and records the stale keys in
 * the CachePort. The SSE stream watches that version — a cheap cache read per
 * tick, NOT a DB scan — and forwards the keys to subscribed clients, who then
 * partial-reload through the normal authorized pipeline.
 *
 * SECURITY: the channel NAME is the scope. Build it from identity/tenant on the
 * server (never from client input) so one principal can't watch another's
 * channel. Only key NAMES travel — never data — so the stream leaks nothing.
 */
final class PageflowChannel
{
    private const PREFIX = 'pageflow:chan:';
    /** How far back a resuming client may replay (bounds the cache reads). */
    private const MAX_LOOKBACK = 50;
    /** TTL for a version's key list — long enough to survive a reconnect. */
    private const KEYS_TTL = 300;

    public function __construct(private readonly CachePort $cache)
    {
    }

    /**
     * Mark prop keys stale on a channel. Returns the new version.
     *
     * @param list<string> $keys
     */
    public function touch(string $channel, array $keys): int
    {
        $channel = $this->normalize($channel);
        $clean = array_values(array_filter(
            $keys,
            static fn($k): bool => is_string($k) && $k !== '',
        ));
        if ($clean === []) {
            return (int) ($this->cache->get($this->versionKey($channel)) ?? 0);
        }

        $version = $this->cache->increment($this->versionKey($channel));
        $this->cache->set($this->keysKey($channel, $version), $clean, self::KEYS_TTL);

        return $version;
    }

    /**
     * The stale keys accumulated since `$since`, plus the current version to
     * resume from. Replay is capped at MAX_LOOKBACK versions.
     *
     * @return array{0:int,1:list<string>} [currentVersion, keys]
     */
    public function stale(string $channel, int $since): array
    {
        $channel = $this->normalize($channel);
        $current = (int) ($this->cache->get($this->versionKey($channel)) ?? 0);

        if ($current <= $since) {
            return [$current, []];
        }

        $from = max($since, $current - self::MAX_LOOKBACK) + 1;
        $keys = [];
        for ($v = $from; $v <= $current; $v++) {
            $batch = $this->cache->get($this->keysKey($channel, $v));
            if (is_array($batch)) {
                foreach ($batch as $key) {
                    if (is_string($key) && $key !== '') {
                        $keys[$key] = true; // dedupe
                    }
                }
            }
        }

        return [$current, array_keys($keys)];
    }

    /**
     * Open an authenticated SSE stream for a channel. The cursor is carried in
     * the SSE `id:` field, so a reconnecting browser resumes exactly (via
     * Last-Event-ID) without replaying the whole history.
     */
    public function stream(Request $request, string $channel, int $intervalMs = 2000, int $maxSeconds = 300): Response
    {
        $self = $this;
        $channel = $this->normalize($channel);

        // Resume from Last-Event-ID; otherwise start at the current version so a
        // fresh subscriber isn't spammed with historical changes.
        $header = (string) ($request->header('Last-Event-ID') ?? '');
        $cursor = ctype_digit($header)
            ? (int) $header
            : (int) ($this->cache->get($this->versionKey($channel)) ?? 0);

        $resolver = static function () use ($self, $channel, &$cursor): array {
            [$version, $keys] = $self->stale($channel, $cursor);
            $cursor = $version;
            return ['keys' => $keys, 'id' => (string) $version];
        };

        // A bounded lifetime recycles the connection (EventSource auto-reconnects)
        // so a stream can't pin a worker forever — the key DoS guard under FPM.
        return PageflowStream::open($request, $resolver, $intervalMs, $maxSeconds);
    }

    private function normalize(string $channel): string
    {
        // Restrict to a safe key charset to prevent cache-key injection.
        $clean = preg_replace('/[^A-Za-z0-9:_\-.]/', '', $channel) ?? '';
        return $clean !== '' ? $clean : 'default';
    }

    private function versionKey(string $channel): string
    {
        return self::PREFIX . $channel . ':v';
    }

    private function keysKey(string $channel, int $version): string
    {
        return self::PREFIX . $channel . ':k:' . $version;
    }
}
