<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\OAuth2\Application\Ports\ScopeStore;

final class ScopeRepository implements ScopeStore
{
    public function __construct(private readonly DatabasePort $db)
    {
    }

    public function exists(string $scope): bool
    {
        try {
            return $this->db->queryOne(
                'SELECT id FROM oauth_scopes WHERE id = :id',
                ['id' => $scope],
            ) !== null;
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to check scope', layer: 'repository.oauth', previous: $e);
        }
    }

    /** @return list<string> */
    public function all(): array
    {
        try {
            $rows = $this->db->query('SELECT id FROM oauth_scopes ORDER BY id');
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to list scopes', layer: 'repository.oauth', previous: $e);
        }

        return array_map(static fn (array $r): string => (string) $r['id'], $rows);
    }

    /** @return array<string,string> */
    public function describe(): array
    {
        try {
            $rows = $this->db->query('SELECT id, description FROM oauth_scopes ORDER BY id');
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to list scopes', layer: 'repository.oauth', previous: $e);
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['id']] = (string) ($r['description'] ?? '');
        }

        return $out;
    }
}
