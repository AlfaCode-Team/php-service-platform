<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Tenancy\API\Contracts\InvitationServiceContract;
use Plugins\Tenancy\Domain\Exceptions\InvalidInvitationException;
use Plugins\User\API\Contracts\UserServiceContract;
use Project\Http\Controllers\ApiController;

/**
 * Accept-invitation endpoint. The invited email must match the AUTHENTICATED
 * user's email — which we read from the User identity store (never from the
 * request body), so a user can only accept invitations issued to their own
 * verified address.
 */
final class InvitationController extends ApiController
{
    public function __construct(
        private readonly InvitationServiceContract $invitations,
        private readonly UserServiceContract $users,
    ) {}

    /** POST /api/invitations/accept  { "token": "…" } */
    public function accept(): Response
    {
        $identity = $this->identity();
        if ($identity->isGuest()) {
            return $this->forbidden('Authentication is required.');
        }

        $request = $this->resolveRequest();
        $token   = trim((string) $request->input('token'));
        if ($token === '') {
            return $this->unprocessable(['token' => 'A token is required.']);
        }

        $user = $this->users->find($identity->userId);
        if ($user === null) {
            return $this->forbidden('Unknown user.');
        }

        try {
            $tenantId = $this->invitations->accept($token, $identity->userId, $user->email, $request->ip());
        } catch (InvalidInvitationException $e) {
            return $this->unprocessable(['token' => $e->getMessage()]);
        }

        return $this->ok(['tenantId' => $tenantId]);
    }
}
