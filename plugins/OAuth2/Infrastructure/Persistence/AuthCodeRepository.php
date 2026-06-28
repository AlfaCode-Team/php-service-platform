<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\OAuth2\Application\Ports\AuthCodeStore;
use Plugins\OAuth2\Domain\Entities\AuthCode;

final class AuthCodeRepository implements AuthCodeStore
{
    public function __construct(private readonly DatabasePort $db)
    {
    }

    public function store(AuthCode $code, string $codeHash): void
    {
        try {
            $this->db->execute(
                'INSERT INTO oauth_auth_codes
                    (id, code_hash, client_id, user_id, redirect_uri, scopes, code_challenge, code_challenge_method, nonce, consumed, expires_at, created_at)
                 VALUES
                    (:id, :hash, :client, :user, :redirect, :scopes, :challenge, :method, :nonce, 0, :expires, :created)',
                [
                    'id'        => $code->id,
                    'hash'      => $codeHash,
                    'client'    => $code->clientId,
                    'user'      => $code->userId,
                    'redirect'  => $code->redirectUri,
                    'scopes'    => json_encode(array_values($code->scopes)),
                    'challenge' => $code->codeChallenge,
                    'method'    => $code->codeChallengeMethod,
                    'nonce'     => $code->nonce,
                    'expires'   => $code->expiresAt->format('Y-m-d H:i:s'),
                    'created'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to store authorization code', layer: 'repository.oauth', previous: $e);
        }
    }

    public function findByHash(string $codeHash): ?AuthCode
    {
        try {
            $row = $this->db->queryOne(
                'SELECT * FROM oauth_auth_codes WHERE code_hash = :hash',
                ['hash' => $codeHash],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to load authorization code', layer: 'repository.oauth', previous: $e);
        }

        if ($row === null) {
            return null;
        }

        $scopes = json_decode((string) ($row['scopes'] ?? '[]'), true);

        return new AuthCode(
            id:                  (string) $row['id'],
            clientId:            (string) $row['client_id'],
            userId:              (string) $row['user_id'],
            redirectUri:         (string) $row['redirect_uri'],
            scopes:              is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [],
            codeChallenge:       ($row['code_challenge'] ?? null) ?: null,
            codeChallengeMethod: ($row['code_challenge_method'] ?? null) ?: null,
            expiresAt:           new \DateTimeImmutable((string) $row['expires_at']),
            consumed:            (bool) $row['consumed'],
            nonce:               ($row['nonce'] ?? null) ?: null,
        );
    }

    public function consume(string $codeId): bool
    {
        try {
            // Atomic single-use: only the first caller flips consumed 0→1.
            return $this->db->execute(
                'UPDATE oauth_auth_codes SET consumed = 1 WHERE id = :id AND consumed = 0',
                ['id' => $codeId],
            ) === 1;
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to consume authorization code', layer: 'repository.oauth', previous: $e);
        }
    }

    public function deleteExpired(?\DateTimeImmutable $now = null): int
    {
        $cutoff = ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            return $this->db->execute(
                'DELETE FROM oauth_auth_codes WHERE expires_at <= :cutoff',
                ['cutoff' => $cutoff],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to prune authorization codes', layer: 'repository.oauth', previous: $e);
        }
    }
}
