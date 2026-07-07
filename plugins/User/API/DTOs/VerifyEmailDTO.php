<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

/**
 * Email-verification input: a signed/opaque token the user received by email.
 * The token is verified out-of-band by the service (timing-safe compare).
 */
final readonly class VerifyEmailDTO
{
    public function __construct(
        public string $token,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $token = trim((string) $request->input('token', ''));
        if ($token === '') {
            throw new ValidationException(['token' => 'A verification token is required.']);
        }

        return new self(token: $token);
    }
}
