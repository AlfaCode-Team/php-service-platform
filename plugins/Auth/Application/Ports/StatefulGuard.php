<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Ports;

/**
 * StatefulGuard — a guard that can LOG a user in and out (session-backed), not
 * just read the current one. GDA-native port of the old __DEV__ StatefulDriver
 * contract. Implemented by StatefulSessionGuard.
 */
interface StatefulGuard
{
    /**
     * Attempt to authenticate a user by credentials; logs them in on success.
     *
     * @param array<string,string> $credentials
     */
    public function attempt(array $credentials = [], bool $remember = false): bool;

    /**
     * Validate credentials WITHOUT logging in (no session written).
     *
     * @param array<string,string> $credentials
     */
    public function validate(array $credentials = []): bool;

    /**
     * Authenticate for a SINGLE request without persisting a session.
     *
     * @param array<string,string> $credentials
     */
    public function once(array $credentials = []): bool;

    /** Log the given user in and persist the session (+ optional remember-me). */
    public function login(Authenticatable $user, bool $remember = false): void;

    /** Log a user in by id; false when no such user. */
    public function loginUsingId(string $id, bool $remember = false): Authenticatable|false;

    /** Set the user for a single request by id (no session write). */
    public function onceUsingId(string $id): Authenticatable|false;

    /** True when the current user was authenticated via a remember-me cookie. */
    public function viaRemember(): bool;

    /** Log the current user out (clears session + remember-me). */
    public function logout(): void;
}
