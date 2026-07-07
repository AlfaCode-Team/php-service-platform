<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Auth;

use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Auth\Application\Ports\Authenticatable;
use Plugins\Auth\Application\Ports\UserProvider;
use Plugins\User\API\Contracts\UserServiceContract;

/**
 * ModelUserProvider — the default UserProvider service, backed by the central
 * identity store (UserServiceContract), NOT an ORM model.
 *
 * GDA-native successor to the old __DEV__ ModelUserProvider: it delegates every
 * lookup to the published User contract (which is timing-safe, rate-limited and
 * hides the password hash) and returns a lightweight AuthUserProxy. No entity
 * hydration, no `app()`, no global project entity.
 */
final class ModelUserProvider implements UserProvider
{
    /** @param list<string> $lookupFields ordered credential keys to try */
    public function __construct(
        private readonly UserServiceContract $users,
        private readonly string $providerName = 'users',
        private readonly array $lookupFields = ['identifier', 'email', 'username'],
        private readonly ?AuthServiceContract $tokens = null,
    ) {}

    public function name(): string
    {
        return $this->providerName;
    }

    public function retrieveById(string $id): ?Authenticatable
    {
        if ($id === '') {
            return null;
        }

        $user = $this->users->find($id);

        return $user === null ? null : AuthUserProxy::fromUser($user, tokensService: $this->tokens);
    }

    public function retrieveByToken(string $rememberToken): ?Authenticatable
    {
        $user = $this->users->findByRememberToken($rememberToken);

        return $user === null ? null : AuthUserProxy::fromUser($user, tokensService: $this->tokens);
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $identifier = '';
        foreach ($this->lookupFields as $field) {
            if (($credentials[$field] ?? '') !== '') {
                $identifier = (string) $credentials[$field];
                break;
            }
        }

        $password = (string) ($credentials['password'] ?? '');
        if ($identifier === '' || $password === '') {
            return null;
        }

        // Single timing-safe verify (unknown user, wrong password, inactive, or
        // lockout all return null). The store never exposes the hash.
        $user = $this->users->verifyCredentials($identifier, $password);

        return $user === null ? null : AuthUserProxy::fromUser($user, tokensService: $this->tokens);
    }
}
