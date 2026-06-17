<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\SecurityVerdict;

/**
 * Every security layer implements this contract.
 * Layers run in order — first deny short-circuits all remaining layers.
 * NEVER throw from a security layer — return SecurityVerdict::deny() instead.
 */
interface SecurityLayerContract
{
    public function check(Request $request): SecurityVerdict;
}
