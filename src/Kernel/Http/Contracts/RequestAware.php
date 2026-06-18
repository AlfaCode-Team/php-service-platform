<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Http\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

/**
 * A handler that wants the active Request injected before its action runs.
 *
 * The kernel passes the Request to the action method, but a controller may also
 * want to hold it (controllers are request-scoped, so this is leak-safe). When a
 * resolved controller implements this interface, ExecuteStage calls setRequest()
 * with the SAME Request the action receives — the only copy carrying the
 * request-scoped container — before invoking the action.
 *
 * The kernel stays agnostic about WHY a controller wants the request (cookies,
 * locale, etc.); it only honours the contract.
 */
interface RequestAware
{
    public function setRequest(Request $request): static;
}
