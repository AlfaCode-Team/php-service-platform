<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\User\Domain\ValueObjects\Email;
use Plugins\User\Domain\ValueObjects\PasswordPolicy;
use Plugins\User\Domain\ValueObjects\Username;

/**
 * Validated registration input. Field-shape validation happens here; the value
 * objects (Username, Email) enforce the deeper domain rules.
 *
 * The plaintext password is held only long enough for the service to hash it
 * via the HashingPort, then discarded — it is never persisted or logged.
 */
final readonly class RegisterUserDTO
{
    public function __construct(
        public Username $username,
        public Email $email,
        public string $password,
        /**
         * Originating tenant for a self-signup, taken from the request 'tenant'
         * attribute set by Tenancy's TenantContextStage. Opaque string — User
         * stays tenant-agnostic and merely forwards it on the user.registered
         * event so Tenancy can assign membership. '' when there is no tenant.
         */
        public string $tenantId = '',
    ) {}

    public static function fromRequest(Request $request): self
    {
        $errors = [];

        $usernameRaw = trim((string) $request->input('username', ''));
        $emailRaw    = trim((string) $request->input('email', ''));
        $password    = (string) $request->input('password', '');

        $username = null;
        try {
            $username = Username::fromString($usernameRaw);
        } catch (\DomainException $e) {
            $errors['username'] = $e->getMessage();
        }

        $email = null;
        try {
            $email = Email::fromString($emailRaw);
        } catch (\DomainException $e) {
            $errors['email'] = $e->getMessage();
        }

        // Strength rules are centralised in the PasswordPolicy value object.
        $errors += PasswordPolicy::validate($password);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var Username $username */
        /** @var Email $email */
        return new self(
            username: $username,
            email:    $email,
            password: $password,
            tenantId: (string) ($request->attribute('tenant') ?? ''),
        );
    }
}
