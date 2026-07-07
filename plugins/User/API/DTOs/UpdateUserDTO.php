<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\User\Domain\ValueObjects\Email;
use Plugins\User\Domain\ValueObjects\PasswordPolicy;
use Plugins\User\Domain\ValueObjects\Username;

/**
 * Partial-update input. Every field is optional: only the keys PRESENT in the
 * request are applied (PATCH semantics), so a caller can change just the email
 * without resending the username or password.
 *
 * The plaintext password (when present) is held only long enough for the
 * service to hash it via the HashingPort, then discarded.
 */
final readonly class UpdateUserDTO
{
    public function __construct(
        public ?Username $username,
        public ?Email $email,
        public ?string $password,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $errors = [];

        $username = null;
        if ($request->has('username')) {
            try {
                $username = Username::fromString(trim((string) $request->input('username', '')));
            } catch (\DomainException $e) {
                $errors['username'] = $e->getMessage();
            }
        }

        $email = null;
        if ($request->has('email')) {
            try {
                $email = Email::fromString(trim((string) $request->input('email', '')));
            } catch (\DomainException $e) {
                $errors['email'] = $e->getMessage();
            }
        }

        $password = null;
        if ($request->has('password')) {
            $password = (string) $request->input('password', '');
            $errors += PasswordPolicy::validate($password);
        }

        if ($username === null && $email === null && $password === null && $errors === []) {
            $errors['_'] = 'Provide at least one of: username, email, password.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(username: $username, email: $email, password: $password);
    }

    public function hasChanges(): bool
    {
        return $this->username !== null || $this->email !== null || $this->password !== null;
    }
}
