<?php

declare(strict_types=1);

namespace Plugins\Auth\API;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;

/**
 * Guard — a stateless, read-only projection over the request Identity.
 *
 * This is the GDA-native replacement for the old Laravel-style AuthManager +
 * named guards. There is no per-request driver factory and no mutable state:
 * the SecurityGateway chain (JwtAuthLayer -> PersonalAccessTokenLayer ->
 * SessionAuthStage) IS the driver chain, and it has already resolved WHO the
 * caller is and by WHICH credential type by the time a controller runs. This
 * object just reads that verdict off the immutable Request.
 *
 * Old ergonomics -> new surface:
 *   Auth::check()          -> $guard->check()
 *   Auth::user()           -> $guard->user()   (the Identity)
 *   Auth::id()             -> $guard->id()
 *   Auth::guard('jwt')     -> $guard->via() === 'jwt'
 *   $token->can('scope')   -> $guard->hasScope('scope')
 *
 * Build one with Guard::fromRequest($request) or the AuthServiceContract::guard()
 * helper; controllers get it via the InteractsWithAuth concern.
 */
final readonly class Guard
{
    public function __construct(private Identity $identity) {}

    public static function fromRequest(Request $request): self
    {
        return new self($request->identity() ?? Identity::guest());
    }

    /**
     * Test/setup helper — build a Guard for a synthetic user with the given
     * scopes. GDA-native parity with the old OAuth::actingAs(): scopes become
     * hierarchical permissions honoured by hasScope().
     *
     * @param list<string> $scopes
     * @param list<string> $roles
     */
    public static function actingAs(
        string $userId,
        array $scopes = [],
        array $roles = [],
        string $tenantId = '',
        string $tokenType = 'jwt',
    ): self {
        return new self(new Identity($userId, $tenantId, $roles, array_values($scopes), $tokenType));
    }

    /** True when a non-guest Identity authenticated this request. */
    public function check(): bool
    {
        return !$this->identity->isGuest();
    }

    /** True when nobody authenticated (public/anonymous request). */
    public function guest(): bool
    {
        return $this->identity->isGuest();
    }

    /** The resolved Identity (guest Identity when unauthenticated). */
    public function user(): Identity
    {
        return $this->identity;
    }

    /** The authenticated user id, or '' for a guest. */
    public function id(): string
    {
        return $this->identity->userId;
    }

    /** The tenant this request is scoped to ('' = central/unscoped). */
    public function tenantId(): string
    {
        return $this->identity->tenantId;
    }

    /**
     * Which credential type authenticated the request: 'jwt' | 'api_key' |
     * 'session' | 'none'. This is the "named guard" the old AuthManager exposed,
     * but derived from the actual verdict rather than selected up front.
     */
    public function via(): string
    {
        return $this->identity->tokenType;
    }

    /** True when authenticated by a Bearer credential (JWT or PAT), not a session. */
    public function viaToken(): bool
    {
        return $this->identity->tokenType === 'jwt'
            || $this->identity->tokenType === 'api_key';
    }

    /** True when authenticated by a stateful web/AJAX session. */
    public function viaSession(): bool
    {
        return $this->identity->tokenType === 'session';
    }

    public function hasRole(string $role): bool
    {
        return $this->identity->hasRole($role);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->identity->hasPermission($permission);
    }

    /**
     * Token scope check — the GDA equivalent of the old $token->can($scope).
     * OAuth2 access-token scopes land in Identity.permissions namespaced as
     * "scope:<name>" (see OAuth2 TokenIssuer); first-party PAT abilities land
     * as bare permissions. Accept either form.
     *
     * Scopes are HIERARCHICAL (colon-delimited): a held scope satisfies any of
     * its descendants, so a token carrying `admin` passes a required
     * `admin:users:write` (port of the old ResolvesInheritedScopes). `*` grants
     * everything.
     */
    public function hasScope(string $scope): bool
    {
        return ScopeInheritance::satisfies($this->identity->permissions, $scope);
    }
}
