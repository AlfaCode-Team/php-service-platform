<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Ports;

/**
 * UserProvider — resolves an Authenticatable from a data source. The AuthManager
 * manages one or more NAMED providers (config/auth.php `providers`), so a
 * deployment can back different guards with different stores (the default
 * `users` provider is ModelUserProvider over UserServiceContract).
 *
 * Deviation from the old Laravel-style split: `retrieveByCredentials` here does
 * the FULL timing-safe verification (returns the user only on success), because
 * the User module deliberately never exposes the password hash. There is no
 * separate validateCredentials(hash) step to leak.
 */
interface UserProvider
{
    /** Provider name from config (e.g. 'users'). */
    public function name(): string;

    /** Load a user by public id (`user_id`). Null if absent/ineligible. */
    public function retrieveById(string $id): ?Authenticatable;

    /** Load a user by a plaintext "remember me" token. Null on any miss. */
    public function retrieveByToken(string $rememberToken): ?Authenticatable;

    /**
     * Verify credentials and return the user on success (timing-safe, delegated
     * to the store). Null on unknown user, wrong password, inactive, or lockout.
     *
     * @param array{identifier?:string,email?:string,username?:string,password?:string} $credentials
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable;
}
