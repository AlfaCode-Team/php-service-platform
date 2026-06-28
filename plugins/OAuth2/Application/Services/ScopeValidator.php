<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Application\Services;

use Plugins\OAuth2\Application\Ports\ScopeStore;
use Plugins\OAuth2\Domain\Entities\Client;
use Plugins\OAuth2\Domain\Exceptions\OAuthException;

/**
 * Validates a requested scope string against the registered scope catalogue and
 * the client's allowed scopes. Returns the normalised scope list.
 */
final class ScopeValidator
{
    public function __construct(private readonly ScopeStore $scopes)
    {
    }

    /**
     * @return list<string>
     * @throws OAuthException invalid_scope when a scope is unknown or not allowed for the client.
     */
    public function validate(?string $requested, Client $client): array
    {
        $requested = trim((string) $requested);

        // No scope requested → fall back to the client's registered scopes (or none).
        if ($requested === '') {
            return array_values($client->scopes);
        }

        $list = array_values(array_filter(preg_split('/\s+/', $requested) ?: []));

        foreach ($list as $scope) {
            if (!$this->scopes->exists($scope)) {
                throw OAuthException::invalidScope("Unknown scope: {$scope}.");
            }
            if ($client->scopes !== [] && !in_array($scope, $client->scopes, true)) {
                throw OAuthException::invalidScope("Scope not allowed for this client: {$scope}.");
            }
        }

        return $list;
    }
}
