<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Ports;

/**
 * PasswordBroker — orchestrates the "forgot password" flow: mint a reset token,
 * verify it, and set the new password. GDA-native port of the old __DEV__
 * PasswordBroker/CanResetPassword contracts.
 *
 * Statuses are returned (never thrown) so a controller can map them to a generic,
 * enumeration-safe response.
 */
interface PasswordBroker
{
    public const RESET_LINK_SENT = 'passwords.sent';
    public const PASSWORD_RESET  = 'passwords.reset';
    public const INVALID_USER    = 'passwords.user';
    public const INVALID_TOKEN   = 'passwords.token';
    public const THROTTLED       = 'passwords.throttled';

    /**
     * Create a reset token for the account behind $email and return a result the
     * caller can email. On an unknown user, returns INVALID_USER WITHOUT a token
     * (the controller should still respond generically to avoid enumeration).
     *
     * @return array{status:string, token?:string, userId?:string, email?:string}
     */
    public function sendResetLink(string $email): array;

    /** Whether a (email, token) pair is currently valid. */
    public function validateToken(string $email, string $token): bool;

    /**
     * Consume a valid token and set the new password. Returns PASSWORD_RESET on
     * success, else INVALID_TOKEN / INVALID_USER.
     */
    public function reset(string $email, string $token, string $newPassword): string;
}
