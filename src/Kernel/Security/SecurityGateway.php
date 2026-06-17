<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\SecurityLayerContract;

// ─── SecurityGateway ─────────────────────────────────────────────────────────

/**
 * Pre-bootstrap security gate.
 * Runs BEFORE any module loads into memory.
 * Denied requests never touch module code — zero module cost.
 *
 * Layer order matters: cheapest first.
 *   1. FirewallLayer       (IP blocklist — nanoseconds)
 *   2. RateLimiterLayer    (cache counter — microseconds)
 *   3. CsrfTokenLayer      (timing-safe string compare — microseconds)
 *   4. [Auth module layer] (token verify — milliseconds, optional)
 */
final class SecurityGateway
{
    /** @param SecurityLayerContract[] $layers */
    public function __construct(
        private readonly array $layers
    ) {}

    public function inspect(Request $request): SecurityVerdict
    {
        foreach ($this->layers as $layer) {
            $verdict = $layer->check($request);

            if ($verdict->isDenied()) {
                return $verdict;  // Short-circuit — nothing else runs
            }

            // If this layer resolved an identity, attach it to the request
            if ($verdict->identity() !== null) {
                $request = $request->withIdentity($verdict->identity());
            }
        }

        return SecurityVerdict::allow($request);
    }
}

