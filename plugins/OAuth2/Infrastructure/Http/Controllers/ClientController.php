<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use Plugins\OAuth2\Application\Ports\ClientStore;
use Project\Http\Controllers\ApiController;

/**
 * Self-service OAuth client management for the authenticated user. GDA-native
 * port of the old __DEV__ Passport ClientController — backed by ClientStore, all
 * actions scoped to the caller's Identity (owner_id).
 *
 *   GET    /oauth/clients        list MY clients
 *   POST   /oauth/clients        register a client (secret shown ONCE)
 *   PUT    /oauth/clients/{id}    update name/redirects/scopes of MY client
 *   DELETE /oauth/clients/{id}    revoke MY client
 */
final class ClientController extends ApiController
{
    /** Grants a browser-based confidential client may use by default. */
    private const DEFAULT_GRANTS = ['authorization_code', 'refresh_token'];

    public function __construct(
        private readonly ClientStore $clients,
        private readonly HashingPort $hasher,
    ) {
    }

    public function forUser(): Response
    {
        $userId = $this->requireUser();
        if ($userId === null) {
            return Response::unauthorized('Authentication required.');
        }

        $clients = array_map(
            static fn($c) => $c->toPublicArray(),
            $this->clients->findByOwner($userId),
        );

        return $this->ok(['clients' => $clients]);
    }

    public function store(): Response
    {
        $userId = $this->requireUser();
        if ($userId === null) {
            return Response::unauthorized('Authentication required.');
        }

        $name = trim((string) $this->request?->input('name', ''));
        if ($name === '') {
            return $this->unprocessable(['name' => 'A client name is required.']);
        }

        $redirects = $this->list($this->request?->input('redirect_uris', []));
        $scopes    = $this->list($this->request?->input('scopes', []));
        $public    = $this->request?->boolean('public') ?? false;

        $id         = bin2hex(random_bytes(16));
        $secret     = null;
        $secretHash = null;
        if (!$public) {
            $secret     = bin2hex(random_bytes(32));
            $secretHash = $this->hasher->make($secret);
        }

        $grants = $public ? ['authorization_code', 'refresh_token'] : self::DEFAULT_GRANTS;

        $this->clients->create($id, $name, $secretHash, $redirects, $grants, $scopes, !$public, $userId);

        // client_secret is returned exactly once.
        return $this->created(array_filter([
            'id'            => $id,
            'name'          => $name,
            'client_secret' => $secret,
            'redirect_uris' => $redirects,
            'scopes'        => $scopes,
            'confidential'  => !$public,
        ], static fn($v) => $v !== null));
    }

    public function update(string $id): Response
    {
        $userId = $this->requireUser();
        if ($userId === null) {
            return Response::unauthorized('Authentication required.');
        }

        $client = $this->clients->find($id);
        if ($client === null || $client->ownerId() !== $userId) {
            return Response::notFound();
        }

        $name = trim((string) $this->request?->input('name', $client->name));
        if ($name === '') {
            return $this->unprocessable(['name' => 'A client name is required.']);
        }

        $redirects = $this->list($this->request?->input('redirect_uris', $client->redirectUris ?? []));
        $scopes    = $this->list($this->request?->input('scopes', $client->scopes ?? []));

        $this->clients->updateDetails($id, $name, $redirects, $scopes);

        return $this->ok($this->clients->find($id)?->toPublicArray() ?? []);
    }

    public function destroy(string $id): Response
    {
        $userId = $this->requireUser();
        if ($userId === null) {
            return Response::unauthorized('Authentication required.');
        }

        $client = $this->clients->find($id);
        if ($client === null || $client->ownerId() !== $userId) {
            return Response::notFound();
        }

        $this->clients->revoke($id);

        return $this->noContent();
    }

    private function requireUser(): ?string
    {
        $identity = $this->identity();

        return $identity->isGuest() ? null : $identity->userId;
    }

    /** @return list<string> */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
