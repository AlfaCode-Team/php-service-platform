<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Project\Http\Controllers\ViewController;

/**
 * Serves the HTML shells for the tenancy UI. The pages hydrate over AJAX by
 * calling the JSON endpoints under /ajx; the browser session cookie
 * authenticates those calls automatically (same-site — no bearer token).
 *
 * Every page is handed a freshly minted CSRF token (kernel CsrfTokenLayer, HMAC,
 * bound to the session cookie) by {@see ViewController::view()}. Views embed it
 * as a <meta> tag and send it in the X-CSRF-Token header on every unsafe request
 * so the SecurityGateway accepts the mutation.
 */
final class TenantPageController extends ViewController
{
    protected const API_BASE = '/ajx';

    /** GET /tenants — the tenant picker for the authenticated user. */
    public function index(): Response
    {
        return $this->view('tenancy::tenants/index', ['title' => 'Your tenants'], 'tenancy::layouts/app');
    }

    /** GET /tenant/hosts — manage the current tenant's custom domains. */
    public function hosts(): Response
    {
        return $this->view('tenancy::hosts/index', ['title' => 'Tenant hosts'], 'tenancy::layouts/app');
    }
}
