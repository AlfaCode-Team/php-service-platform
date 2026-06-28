<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Application\Ports;

interface ScopeStore
{
    /** True when the scope is a registered, grantable scope. */
    public function exists(string $scope): bool;

    /** @return list<string> all registered scope identifiers */
    public function all(): array;
}
