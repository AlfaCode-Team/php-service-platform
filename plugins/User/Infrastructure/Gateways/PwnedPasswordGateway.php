<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Gateways;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use Plugins\User\Application\Ports\BreachChecker;

/**
 * PwnedPasswordGateway — Have-I-Been-Pwned "Pwned Passwords" range API.
 *
 * Uses k-ANONYMITY: only the first 5 hex chars of the SHA-1 of the password are
 * ever sent. The API returns every breached suffix sharing that prefix; the
 * match is done locally. The full password (or its full hash) never leaves the
 * process, so the check itself does not weaken the credential.
 *
 * Fails OPEN — any transport error, non-200, or malformed body is treated as
 * "not breached" so an HIBP outage can never block registration / password
 * change. This is a GATEWAY: outbound HTTP goes through HttpClientPort only.
 */
final class PwnedPasswordGateway implements BreachChecker
{
    private const ENDPOINT = 'https://api.pwnedpasswords.com/range/';

    public function __construct(
        private readonly HttpClientPort $http,
        /** Minimum breach count to consider a password compromised (1 = any sighting). */
        private readonly int $threshold = 1,
    ) {
    }

    public function isBreached(string $password): bool
    {
        $sha1   = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        try {
            // "Add-Padding" asks HIBP to pad the response so the exact result
            // count cannot be inferred from the response size.
            $response = $this->http->request('GET', self::ENDPOINT . $prefix, [
                'headers'         => ['Add-Padding' => 'true'],
                'timeout'         => 3,
                'connect_timeout' => 2,
            ]);
        } catch (\Throwable) {
            return false; // fail open
        }

        if (!$response->ok()) {
            return false; // fail open
        }

        foreach (preg_split('/\r\n|\r|\n/', $response->body()) ?: [] as $line) {
            $parts = explode(':', trim($line), 2);
            if (count($parts) !== 2) {
                continue;
            }
            // Padding rows have a count of 0 — ignore them.
            if (strcasecmp($parts[0], $suffix) === 0) {
                return ((int) $parts[1]) >= $this->threshold;
            }
        }

        return false;
    }
}
