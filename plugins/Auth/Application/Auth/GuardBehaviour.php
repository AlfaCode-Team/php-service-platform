<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Auth;

use Plugins\Auth\Application\Ports\Authenticatable;
use Plugins\Auth\Application\Ports\UserProvider;
use Plugins\Auth\Domain\Exceptions\AuthenticationException;

/**
 * GuardBehaviour — shared current-user accessors for guards. GDA-native port of
 * the old __DEV__ DriverHelpers trait. The consuming guard owns `$user`
 * (?Authenticatable) and `$provider` (UserProvider).
 */
trait GuardBehaviour
{
    protected ?Authenticatable $user = null;
    protected UserProvider $provider;

    /** The current user, or throw if unauthenticated. */
    public function authenticate(): Authenticatable
    {
        $user = $this->user();
        if ($user === null) {
            throw new AuthenticationException(guards: [method_exists($this, 'getName') ? $this->getName() : 'default']);
        }

        return $user;
    }

    /** Whether a user has already been resolved for this request (no lookup). */
    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function id(): ?string
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function forgetUser(): static
    {
        $this->user = null;

        return $this;
    }

    public function getProvider(): UserProvider
    {
        return $this->provider;
    }

    public function setProvider(UserProvider $provider): static
    {
        $this->provider = $provider;

        return $this;
    }
}
