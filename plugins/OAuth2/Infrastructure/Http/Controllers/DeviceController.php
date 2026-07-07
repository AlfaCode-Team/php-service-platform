<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\OAuth2\Application\Services\DeviceService;
use Plugins\OAuth2\Domain\Exceptions\OAuthException;
use Plugins\OAuth2\Infrastructure\Http\Concerns\SpeaksOAuth;
use Project\Http\Controllers\ApiController;

/**
 * POST /oauth/device_authorization — start a device flow (RFC 8628 §3.1).
 * Returns the device_code, user_code and verification URIs.
 */
final class DeviceController extends ApiController
{
    use SpeaksOAuth;

    public function __construct(private readonly DeviceService $devices)
    {
    }

    public function authorize(): Response
    {
        $request = $this->resolveRequest();

        try {
            $result = $this->devices->authorize($request->all(), $this->basicClient($request));
        } catch (OAuthException $e) {
            return $this->oauthError($e);
        }

        $verify = (string) $request->site()->to('oauth/device');
        $result['verification_uri']          = $verify;
        $result['verification_uri_complete'] = $verify . '?user_code=' . urlencode($result['user_code']);

        return $this->noStore(Response::json($result));
    }
}
