<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\OAuth2\Application\Ports\ClientStore;
use Plugins\OAuth2\Domain\Entities\Client;

final class ClientRepository implements ClientStore
{
    public function __construct(private readonly DatabasePort $db)
    {
    }

    public function find(string $clientId): ?Client
    {
        try {
            $row = $this->db->queryOne(
                'SELECT * FROM oauth_clients WHERE id = :id',
                ['id' => $clientId],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to load OAuth client', layer: 'repository.oauth', previous: $e);
        }

        if ($row === null) {
            return null;
        }

        return new Client(
            id:           (string) $row['id'],
            name:         (string) $row['name'],
            secretHash:   $row['secret_hash'] !== null && $row['secret_hash'] !== '' ? (string) $row['secret_hash'] : null,
            redirectUris: $this->decodeList($row['redirect_uris'] ?? null),
            grantTypes:   $this->decodeList($row['grant_types'] ?? null),
            scopes:       $this->decodeList($row['scopes'] ?? null),
            confidential: (bool) $row['confidential'],
            revoked:      (bool) $row['revoked'],
        );
    }

    public function create(
        string $id,
        string $name,
        ?string $secretHash,
        array $redirectUris,
        array $grantTypes,
        array $scopes,
        bool $confidential,
    ): void {
        try {
            $this->db->execute(
                'INSERT INTO oauth_clients (id, name, secret_hash, redirect_uris, grant_types, scopes, confidential, revoked, created_at)
                 VALUES (:id, :name, :secret, :redirects, :grants, :scopes, :conf, :revoked, :created)',
                [
                    'id'        => $id,
                    'name'      => $name,
                    'secret'    => $secretHash,
                    'redirects' => json_encode(array_values($redirectUris)),
                    'grants'    => json_encode(array_values($grantTypes)),
                    'scopes'    => json_encode(array_values($scopes)),
                    'conf'      => $confidential ? 1 : 0,
                    'revoked'   => 0,
                    'created'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to create OAuth client', layer: 'repository.oauth', previous: $e);
        }
    }

    /** @return list<Client> */
    public function all(): array
    {
        try {
            $rows = $this->db->query('SELECT * FROM oauth_clients ORDER BY created_at DESC');
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to list OAuth clients', layer: 'repository.oauth', previous: $e);
        }

        return array_map(fn (array $row): Client => new Client(
            id:           (string) $row['id'],
            name:         (string) $row['name'],
            secretHash:   $row['secret_hash'] !== null && $row['secret_hash'] !== '' ? (string) $row['secret_hash'] : null,
            redirectUris: $this->decodeList($row['redirect_uris'] ?? null),
            grantTypes:   $this->decodeList($row['grant_types'] ?? null),
            scopes:       $this->decodeList($row['scopes'] ?? null),
            confidential: (bool) $row['confidential'],
            revoked:      (bool) $row['revoked'],
        ), $rows);
    }

    public function revoke(string $id): bool
    {
        try {
            return $this->db->execute('UPDATE oauth_clients SET revoked = 1 WHERE id = :id', ['id' => $id]) === 1;
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to revoke OAuth client', layer: 'repository.oauth', previous: $e);
        }
    }

    public function updateSecret(string $id, string $secretHash): bool
    {
        try {
            return $this->db->execute(
                'UPDATE oauth_clients SET secret_hash = :secret WHERE id = :id AND confidential = 1',
                ['secret' => $secretHash, 'id' => $id],
            ) === 1;
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to rotate OAuth client secret', layer: 'repository.oauth', previous: $e);
        }
    }

    /** @return list<string> */
    private function decodeList(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
