<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\OAuth2\Application\Services\ScopeRegistry;
use Project\Http\Controllers\ApiController;

/**
 * GET /oauth/scopes — the grantable scope catalogue with descriptions. Public
 * (a client integrating against the server needs to know available scopes). Port
 * of the old __DEV__ Passport ScopeController::all().
 */
final class ScopeController extends ApiController
{
    public function __construct(private readonly ScopeRegistry $scopes)
    {
    }

    public function index(): Response
    {
        return $this->ok(['scopes' => $this->scopes->scopes()]);
    }
}
