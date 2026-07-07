<?php

declare(strict_types=1);

namespace Plugins\Auth\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Project\Http\Controllers\ApiController;

/**
 * Self-service personal access token management for the authenticated user.
 * GDA-native port of the old __DEV__ Passport PersonalAccessTokenController —
 * backed by AuthServiceContract (hash-only storage) instead of Eloquent.
 *
 *   GET    /auth/tokens        list my tokens (no secrets)
 *   POST   /auth/tokens        mint a token (plaintext returned ONCE)
 *   DELETE /auth/tokens/{id}   revoke one of MY tokens
 *
 * All actions are scoped to the caller's Identity; a token id that isn't the
 * caller's is treated as not-found (no cross-user revocation).
 */
final class PersonalAccessTokenController extends ApiController
{
    public function __construct(private readonly AuthServiceContract $auth)
    {
    }

    public function index(): Response
    {
        $identity = $this->identity();
        if ($identity->isGuest()) {
            return Response::unauthorized('Authentication required.');
        }

        $tokens = array_map(
            static fn($t) => $t->toArray(),
            $this->auth->tokensFor($identity->userId),
        );

        return $this->ok(['tokens' => $tokens]);
    }

    public function store(): Response
    {
        $identity = $this->identity();
        if ($identity->isGuest()) {
            return Response::unauthorized('Authentication required.');
        }

        $request = $this->request;
        $name    = trim((string) $request?->input('name', 'default')) ?: 'default';
        $abilities = $request?->input('abilities', []);
        $abilities = is_array($abilities) ? array_values(array_filter($abilities, 'is_string')) : [];
        $ttl = $request?->input('ttl');
        $ttl = is_numeric($ttl) ? (int) $ttl : null;

        $result = $this->auth->createPersonalAccessToken($identity->userId, $name, $abilities, $ttl);

        // Plaintext token is returned exactly once.
        return $this->created($result);
    }

    public function destroy(string $id): Response
    {
        $identity = $this->identity();
        if ($identity->isGuest()) {
            return Response::unauthorized('Authentication required.');
        }

        // Ownership check — only revoke a token that belongs to the caller.
        $owns = false;
        foreach ($this->auth->tokensFor($identity->userId) as $token) {
            if ($token->id === $id) {
                $owns = true;
                break;
            }
        }
        if (!$owns) {
            return Response::notFound();
        }

        $this->auth->revokePersonalAccessToken($id);

        return $this->noContent();
    }
}
