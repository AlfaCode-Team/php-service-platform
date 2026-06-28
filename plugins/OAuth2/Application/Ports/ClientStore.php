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
    ): void;

    /** @return list<Client> */
    public function all(): array;

    /** Mark a client revoked (its tokens stop being issued/accepted). */
    public function revoke(string $id): bool;

    /** Replace a confidential client's secret hash (secret rotation). */
    public function updateSecret(string $id, string $secretHash): bool;
}
