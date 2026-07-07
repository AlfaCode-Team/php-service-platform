<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Auth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Auth\Application\Ports\Authenticatable;
use Plugins\Auth\Application\Ports\GuardContext;
use Plugins\Auth\Application\Ports\GuardDriver;

/**
 * GuardAccessor — the per-guard facade AuthManager::guard($name) returns,
 * preserving the old `$manager->guard('web')->user()` ergonomics. Resolves the
 * user lazily once per accessor and caches it (request-scoped).
 */
final class GuardAccessor
{
    private bool $resolved = false;
    private ?Authenticatable $user = null;

    public function __construct(
        private readonly string $name,
        private readonly GuardDriver $driver,
        private readonly GuardContext $context,
        private readonly Request $request,
    ) {}

    /** Guard name (e.g. 'web', 'api'). */
    public function name(): string
    {
        return $this->name;
    }

    /** The authenticated user, or null. Resolved once and cached. */
    public function user(): ?Authenticatable
    {
        if (!$this->resolved) {
            $this->user     = $this->driver->resolve($this->request, $this->context);
            $this->resolved = true;
        }

        return $this->user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return $this->user() === null;
    }

    /** The current user's id, or '' when unauthenticated. */
    public function id(): string
    {
        return $this->user()?->getAuthIdentifier() ?? '';
    }

    /** Kernel Identity for the current user (guest Identity when unauthenticated). */
    public function identity(): Identity
    {
        return $this->user()?->identity() ?? Identity::guest();
    }
}
