<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Application\Ports;

interface ScopeStore
{
    /** True when the scope is a registered, grantable scope. */
    public function exists(string $scope): bool;

    /** @return list<string> all registered scope identifiers */
    public function all(): array;

    /**
     * The scope catalogue with human-readable descriptions (consent screens +
     * the /oauth/scopes endpoint).
     *
     * @return array<string,string> id => description ('' when none stored)
     */
    public function describe(): array;
}
