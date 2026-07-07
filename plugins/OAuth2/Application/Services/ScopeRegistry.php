<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Application\Services;

use Plugins\OAuth2\Application\Ports\ScopeStore;

/**
 * ScopeRegistry — the consent-facing view of the scope catalogue.
 *
 * GDA-native port of the old __DEV__ Passport OAuth/Passport scope registry
 * (scopes()/scopesFor()/tokensCan()/hasScope()), reading descriptions from the
 * oauth_scopes store instead of a static in-memory array.
 */
final class ScopeRegistry
{
    /** @var array<string,string>|null memoised catalogue for this request */
    private ?array $catalogue = null;

    public function __construct(private readonly ScopeStore $scopes) {}

    /**
     * The full catalogue as a list of {id, description} rows.
     *
     * @return list<array{id:string,description:string}>
     */
    public function scopes(): array
    {
        $out = [];
        foreach ($this->describe() as $id => $description) {
            $out[] = ['id' => $id, 'description' => $description];
        }

        return $out;
    }

    /**
     * The catalogue rows for a specific set of scope ids (unknown ids dropped).
     *
     * @param list<string> $ids
     * @return list<array{id:string,description:string}>
     */
    public function scopesFor(array $ids): array
    {
        $catalogue = $this->describe();
        $out = [];
        foreach ($ids as $id) {
            if (array_key_exists($id, $catalogue)) {
                $out[] = ['id' => $id, 'description' => $catalogue[$id]];
            }
        }

        return $out;
    }

    /** True when the scope id is registered/grantable. */
    public function hasScope(string $id): bool
    {
        return array_key_exists($id, $this->describe());
    }

    /**
     * True when EVERY requested scope is registered (the guard the token
     * endpoint uses before issuing). An empty request is always allowed.
     *
     * @param list<string> $scopes
     */
    public function tokensCan(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if (!$this->hasScope($scope)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,string> */
    private function describe(): array
    {
        return $this->catalogue ??= $this->scopes->describe();
    }
}
