<?php

declare(strict_types=1);

namespace Project\Http\Controllers\Concerns;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\KernelException;

/**
 * Holds the active request for RequestAware base controllers.
 *
 * Base controllers implement RequestAware, so ExecuteStage calls setRequest()
 * with the active Request (the one carrying the request-scoped container) BEFORE
 * the action runs. The per-request helper traits (cookies, session) all build on
 * this so they can be called without passing $request explicitly.
 *
 * This concern is shared by InteractsWithCookies and InteractsWithSession. PHP
 * flattens a trait used through several traits exactly once, so a controller that
 * `use`s both helper traits gets a single $request / setRequest() / resolveRequest()
 * with no conflict.
 */
trait HasRequest
{
    /** Request captured for this action (request-scoped — controllers are per-request). */
    protected ?Request $request = null;

    /** Store the active request so the per-request helpers can be called without it. */
    public function setRequest(Request $request): static
    {
        $this->request = $request;

        return $this;
    }

    /** The explicit request when given, else the one captured via setRequest(). */
    protected function resolveRequest(?Request $request = null): Request
    {
        return $request
            ?? $this->request
            ?? throw new KernelException(
                'No Request available — pass one or call setRequest($request) first.',
                layer: 'controller.request',
            );
    }
}
