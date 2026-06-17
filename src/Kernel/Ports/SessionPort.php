<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * SessionPort — the ONLY way modules read/write per-visitor session state.
 *
 * The kernel defines this interface; a plugin/project provides the adapter
 * (e.g. the file/array-backed Store in plugins/Session). A module never touches
 * $_SESSION, PHP session functions, or a cookie directly — it injects this port.
 *
 * Lifecycle (driven by StartSessionStage, not by modules):
 *   start($id)  → load attributes for the incoming session cookie
 *   ...module reads/writes...
 *   save()      → persist via the handler; the stage then sets the cookie
 *
 * Flash data lives for exactly one subsequent request (the classic
 * put-it-now-read-it-next-request pattern), aged by ageFlashData() on start.
 */
interface SessionPort
{
    /** Load the session for the given id (null → a fresh session is generated). */
    public function start(?string $id = null): void;

    public function id(): string;

    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value): void;

    public function has(string $key): bool;

    /** Read and remove a key in one call. */
    public function pull(string $key, mixed $default = null): mixed;

    /** Append a value to an array stored under $key. */
    public function push(string $key, mixed $value): void;

    public function increment(string $key, int $by = 1): int;

    public function forget(string $key): void;

    public function flush(): void;

    /** @return array<string, mixed> */
    public function all(): array;

    // ── Flash (single-request) data ──────────────────────────────────────────

    public function flash(string $key, mixed $value): void;

    /** Keep all (or named) flash data alive for one more request. */
    public function reflash(): void;

    // ── CSRF token ───────────────────────────────────────────────────────────

    public function token(): string;

    public function regenerateToken(): void;

    // ── Identity / fixation defence ──────────────────────────────────────────

    /** New session id, keep data (defends against fixation after login). */
    public function regenerate(): void;

    /** New session id AND wipe all data (logout). */
    public function invalidate(): void;

    /**
     * Whether the session is worth persisting. False for a fresh visitor that
     * arrived with no session cookie and never wrote anything — lets the driving
     * stage skip save() + Set-Cookie so stateless traffic (APIs, bots, health
     * checks) never spawns empty sessions. True once data is written or an
     * existing session was loaded.
     */
    public function shouldPersist(): bool;

    /** Persist the session via its handler. */
    public function save(): void;
}
