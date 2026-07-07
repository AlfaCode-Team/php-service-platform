<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;

/**
 * GuardContext — the per-guard collaborators a driver needs to resolve a user:
 * its configured UserProvider and (for stateful guards) the request session.
 * Immutable and request-scoped.
 */
final readonly class GuardContext
{
    public function __construct(
        public UserProvider $provider,
        public ?SessionPort $session = null,
    ) {}
}
