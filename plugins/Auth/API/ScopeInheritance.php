<?php

declare(strict_types=1);

namespace Plugins\Auth\API;

/**
 * ScopeInheritance — hierarchical (colon-delimited) scope matching. GDA-native
 * port of the old __DEV__ ResolvesInheritedScopes trait.
 *
 * A held scope satisfies itself AND every descendant: holding `admin` grants
 * `admin:users` and `admin:users:write`. The wildcard `*` grants everything.
 * Held scopes may be bare (first-party PAT abilities) or namespaced `scope:<name>`
 * (OAuth2 delegated tokens) — both forms are understood.
 */
final class ScopeInheritance
{
    /**
     * @param list<string> $held      the permissions/abilities the caller holds
     * @param string       $required  the scope being checked
     */
    public static function satisfies(array $held, string $required): bool
    {
        foreach ($held as $permission) {
            if ($permission === '*') {
                return true;
            }

            $scope = str_starts_with($permission, 'scope:') ? substr($permission, 6) : $permission;

            // Exact match, or $scope is an ancestor of $required (admin ⊇ admin:write).
            if ($scope === $required || str_starts_with($required, $scope . ':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Expand a scope into itself plus all ancestors — 'a:b:c' →
     * ['a', 'a:b', 'a:b:c']. Retained for parity with the old API.
     *
     * @return list<string>
     */
    public static function ancestors(string $scope): array
    {
        $parts = explode(':', $scope);
        $out   = [];
        for ($i = 1, $n = count($parts); $i <= $n; $i++) {
            $out[] = implode(':', array_slice($parts, 0, $i));
        }

        return $out;
    }
}
