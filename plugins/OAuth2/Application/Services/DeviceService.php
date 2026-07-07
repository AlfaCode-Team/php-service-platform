<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Application\Services;

use Plugins\OAuth2\Application\Ports\ClientStore;
use Plugins\OAuth2\Application\Ports\DeviceCodeStore;
use Plugins\OAuth2\Domain\Entities\DeviceCode;
use Plugins\OAuth2\Domain\Exceptions\OAuthException;

/**
 * DeviceService — the device authorization endpoint (RFC 8628 §3.1/§3.2).
 *
 * Issues a device_code (returned + stored hashed) and a short, human-typable
 * user_code the user enters at the verification URI to approve the device.
 */
final class DeviceService
{
    /** Unambiguous alphabet for the user code (no 0/O/1/I to avoid typos). */
    private const ALPHABET = 'BCDFGHJKLMNPQRSTVWXZ23456789';

    public function __construct(
        private readonly ClientStore $clients,
        private readonly DeviceCodeStore $devices,
        private readonly ScopeValidator $scopeValidator,
        private readonly int $ttl = 600,
        private readonly int $interval = 5,
    ) {
    }

    /**
     * @param array{0:string,1:string}|null $basic
     * @return array{device_code:string,user_code:string,expires_in:int,interval:int,scope:string}
     */
    public function authorize(array $params, ?array $basic): array
    {
        $clientId = $basic[0] ?? trim($params['client_id'] ?? '');
        if ($clientId === '') {
            throw OAuthException::invalidClient('Missing client_id.');
        }

        $client = $this->clients->find($clientId);
        if ($client === null || $client->revoked) {
            throw OAuthException::invalidClient();
        }
        if (!$client->allowsGrant('urn:ietf:params:oauth:grant-type:device_code')) {
            throw OAuthException::unauthorizedClient('Client may not use the device grant.');
        }

        $scopes = $this->scopeValidator->validate($params['scope'] ?? '', $client);

        $rawDeviceCode = bin2hex(random_bytes(40));
        $userCode      = $this->generateUserCode();
        $expires       = (new \DateTimeImmutable())->add(new \DateInterval('PT' . max(60, $this->ttl) . 'S'));

        $device = DeviceCode::of(
            id:           bin2hex(random_bytes(16)),
            userCode:     $userCode,
            clientId:     $client->id,
            scopes:       $scopes,
            status:       DeviceCode::PENDING,
            userId:       null,
            interval:     $this->interval,
            lastPolledAt: null,
            expiresAt:    $expires,
        );
        $this->devices->store($device, hash('sha256', $rawDeviceCode));

        return [
            'device_code' => $rawDeviceCode,
            'user_code'   => $userCode,
            'expires_in'  => max(60, $this->ttl),
            'interval'    => $this->interval,
            'scope'       => implode(' ', $scopes),
        ];
    }

    /** e.g. "BCDF-GHJK" — 8 chars in two readable groups. */
    private function generateUserCode(): string
    {
        $chars = '';
        for ($i = 0; $i < 8; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($chars, 0, 4) . '-' . substr($chars, 4, 4);
    }
}
