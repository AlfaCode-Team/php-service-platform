<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\OAuth2\Application\Ports\RefreshTokenStore;
use Project\Http\Controllers\ApiController;

/**
 * Self-service view + revocation of the apps a user has authorized. GDA-native
 * port of the old __DEV__ Passport AuthorizedAccessTokenController. Lists the
 * user's active refresh tokens (one per authorized app grant) and revokes the
 * whole rotation family on delete.
 *
 *   GET    /oauth/authorized-tokens        list apps I authorized
 *   DELETE /oauth/authorized-tokens/{id}   revoke a grant (whole family)
 */
final class AuthorizedTokenController extends ApiController
{
    public function __construct(private readonly RefreshTokenStore $refreshTokens)
    {
    }

    public function forUser(): Response
    {
        $userId = $this->requireUser();
        if ($userId === null) {
            return Response::unauthorized('Authentication required.');
        }

        $tokens = array_map(static fn($t) => [
            'id'         => (string) $t->id,
            'client_id'  => (string) $t->clientId,
            'scopes'     => $t->scopes ?? [],
            'expires_at' => $t->expiresAt->format(\DateTimeInterface::RFC3339),
        ], $this->refreshTokens->findByUser($userId));

        return $this->ok(['authorized_tokens' => $tokens]);
    }

    public function destroy(string $id): Response
    {
        $userId = $this->requireUser();
        if ($userId === null) {
            return Response::unauthorized('Authentication required.');
        }

        // Only revoke a grant that belongs to the caller.
        foreach ($this->refreshTokens->findByUser($userId) as $token) {
            if ((string) $token->id === $id) {
                $this->refreshTokens->revokeFamily((string) $token->familyId);

                return $this->noContent();
            }
        }

        return Response::notFound();
    }

    private function requireUser(): ?string
    {
        $identity = $this->identity();

        return $identity->isGuest() ? null : $identity->userId;
    }
}
