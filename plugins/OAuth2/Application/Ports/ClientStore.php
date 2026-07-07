<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Application\Ports;

use Plugins\OAuth2\Domain\Entities\Client;

interface ClientStore
{
    public function find(string $clientId): ?Client;

    /**
     * @param list<string> $redirectUris
     * @param list<string> $grantTypes
     * @param list<string> $scopes
     */
    public function create(
        string $id,
        string $name,
        ?string $secretHash,
        array $redirectUris,
        array $grantTypes,
        array $scopes,
        bool $confidential,
        ?string $ownerId = null,
    ): void;

    /** @return list<Client> */
    public function all(): array;

    /**
     * Clients registered by a given user (self-service management).
     *
     * @return list<Client>
     */
    public function findByOwner(string $ownerId): array;

    /**
     * Update a client's editable details (name/redirects/scopes). False when no
     * such client.
     *
     * @param list<string> $redirectUris
     * @param list<string> $scopes
     */
    public function updateDetails(string $id, string $name, array $redirectUris, array $scopes): bool;

    /** Mark a client revoked (its tokens stop being issued/accepted). */
    public function revoke(string $id): bool;

    /** Replace a confidential client's secret hash (secret rotation). */
    public function updateSecret(string $id, string $secretHash): bool;
}
