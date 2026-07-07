<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\User\API\Contracts\UserServiceContract;
use Plugins\User\API\DTOs\ListUsersQuery;
use Plugins\User\API\DTOs\RegisterUserDTO;
use Plugins\User\API\DTOs\UpdateUserDTO;
use Plugins\User\API\DTOs\VerifyEmailDTO;
use Project\Http\Controllers\ApiController;

/**
 * Thin HTTP boundary — DTO → service → Response. No business logic here.
 *
 * RequestAware (via ApiController): actions take route params only; the active
 * request is available as $this->request / $this->resolveRequest().
 */
final class UserController extends ApiController
{
    public function __construct(
        private readonly UserServiceContract $users,
    ) {}

    public function index(): Response
    {
        $query = ListUsersQuery::fromRequest($this->resolveRequest());
        $page  = $this->users->list($query);

        return Response::json([
            'data' => array_map(static fn($u) => $u->toArray(), $page->items),
            'meta' => $page->meta(),
        ]);
    }

    public function register(): Response
    {
        $user = $this->users->register(RegisterUserDTO::fromRequest($this->resolveRequest()));
        return $this->created($user->toArray(), location: "/ajx/users/{$user->id}");
    }

    public function show(string $id): Response
    {
        return $this->okOrNotFound(
            $this->users->find($id)?->toArray(),
            "User [{$id}] not found.",
        );
    }

    public function update(string $id): Response
    {
        $user = $this->users->update($id, UpdateUserDTO::fromRequest($this->resolveRequest()));
        return $this->okOrNotFound($user?->toArray(), "User [{$id}] not found.");
    }

    public function verifyEmail(string $id): Response
    {
        $user = $this->users->verifyEmail($id, VerifyEmailDTO::fromRequest($this->resolveRequest()));
        return $this->okOrNotFound($user?->toArray(), "User [{$id}] not found.");
    }

    public function destroy(string $id): Response
    {
        return $this->users->delete($id)
            ? $this->noContent()
            : $this->notFound("User [{$id}] not found.");
    }
}
