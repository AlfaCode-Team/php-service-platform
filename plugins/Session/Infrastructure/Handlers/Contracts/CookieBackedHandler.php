<?php

declare(strict_types=1);

namespace Plugins\Session\Infrastructure\Handlers\Contracts;

/**
 * Marker for a session handler that stores its state IN the session cookie
 * rather than server-side keyed by id.
 *
 * For these handlers the session "id" is irrelevant — the whole serialized
 * attribute bag travels in the cookie value. StartSessionStage therefore:
 *   1. binds the client context (for optional fingerprint validation),
 *   2. primes the handler with the incoming cookie value BEFORE start(), and
 *   3. writes outgoing() (the freshly serialized payload) into the cookie AFTER
 *      save(), instead of the session id.
 */
interface CookieBackedHandler
{
    /**
     * Provide the client context used for optional fingerprint binding. Call
     * BEFORE prime()/start() (to validate the incoming cookie) — the same
     * fingerprint is embedded on write().
     */
    public function bindClient(?string $userAgent, ?string $ipAddress): void;

    /** Feed the raw incoming session-cookie value so the next read() can use it. */
    public function prime(?string $rawCookie): void;

    /**
     * The payload to place in the outgoing session cookie after save(), or null
     * when there is nothing to persist (the stage then clears the cookie).
     */
    public function outgoing(): ?string;
}
