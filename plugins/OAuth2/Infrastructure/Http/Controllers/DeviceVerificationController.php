<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\OAuth2\Application\Ports\DeviceCodeStore;
use Plugins\OAuth2\Domain\Entities\DeviceCode;
use Plugins\View\API\Contracts\ViewRendererContract;
use Project\Http\Controllers\ViewController;

/**
 * GET/POST /oauth/device — the user-facing device verification page (RFC 8628 §3.3).
 *
 * The user (authenticated via session) enters the user_code shown on the device
 * and approves or denies. Approval flips the device code to `authorized`, which
 * the device's next poll redeems for tokens.
 */
final class DeviceVerificationController extends ViewController
{
    public function __construct(
        ViewRendererContract $renderer,
        private readonly DeviceCodeStore $devices,
    ) {
        parent::__construct($renderer);
    }

    public function show(): Response
    {
        $request  = $this->resolveRequest();
        $identity = $request->identity();
        if ($identity === null || $identity->isGuest()) {
            return $this->redirect('/login?return=' . urlencode((string) $request->uri()));
        }

        return $this->view('oauth2::device', [
            'csrf'     => $this->_csrfToken(),
            'userCode' => (string) $request->query('user_code'),
            'message'  => '',
        ]);
    }

    public function submit(): Response
    {
        $request  = $this->resolveRequest();
        $identity = $request->identity();
        if ($identity === null || $identity->isGuest()) {
            return Response::unauthorized('Login required.');
        }

        $userCode = strtoupper(trim((string) $request->input('user_code')));
        $device   = $userCode === '' ? null : $this->devices->findByUserCode($userCode);

        if ($device === null || $device->isExpired() || $device->status !== DeviceCode::PENDING) {
            return $this->view('oauth2::device', [
                'csrf'     => $this->_csrfToken(),
                'userCode' => $userCode,
                'message'  => 'That code is invalid, expired, or already used.',
            ], status: 422);
        }

        $approved = in_array($request->input('action'), ['approve', 'allow'], true);
        if ($approved) {
            $this->devices->authorize($device->id, $identity->userId);
            $msg = 'Device approved. You can return to your device.';
        } else {
            $this->devices->deny($device->id);
            $msg = 'Device denied.';
        }

        return $this->view('oauth2::device', [
            'csrf'     => $this->_csrfToken(),
            'userCode' => '',
            'message'  => $msg,
        ]);
    }
}
