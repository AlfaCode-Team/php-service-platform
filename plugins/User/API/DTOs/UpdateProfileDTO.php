<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

/**
 * Validated profile-update input (idempotent full replace). The user id is NEVER
 * read from the body — it comes from the authenticated Identity in the service.
 *
 * Field-level validation happens HERE; the UserProfile entity re-guards the same
 * rules as defense-in-depth.
 */
final readonly class UpdateProfileDTO
{
    public function __construct(
        public ?string $firstName,
        public ?string $lastName,
        public ?string $avatarUrl,
        public ?string $timezone,
        public ?string $locale,
        public ?string $phone,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $errors = [];

        $firstName = self::trimOrNull($request->input('firstName'));
        $lastName  = self::trimOrNull($request->input('lastName'));
        $avatarUrl = self::trimOrNull($request->input('avatarUrl'));
        $timezone  = self::trimOrNull($request->input('timezone'));
        $locale    = self::trimOrNull($request->input('locale'));
        $phone     = self::trimOrNull($request->input('phone'));

        if ($firstName !== null && mb_strlen($firstName) > 80) {
            $errors['firstName'] = 'First name cannot exceed 80 characters.';
        }
        if ($lastName !== null && mb_strlen($lastName) > 80) {
            $errors['lastName'] = 'Last name cannot exceed 80 characters.';
        }
        if ($avatarUrl !== null && !self::isHttpUrl($avatarUrl)) {
            $errors['avatarUrl'] = 'Avatar URL must be a valid http(s) URL up to 500 characters.';
        }
        if ($timezone !== null && !in_array($timezone, timezone_identifiers_list(), true)) {
            $errors['timezone'] = 'Unknown timezone.';
        }
        if ($locale !== null && !preg_match('/^[a-z]{2}_[A-Z]{2}$/', $locale)) {
            $errors['locale'] = 'Locale must be in ll_CC form, e.g. en_US.';
        }
        if ($phone !== null && !preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
            $errors['phone'] = 'Phone must be 7–15 digits (optional leading +).';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($firstName, $lastName, $avatarUrl, $timezone, $locale, $phone);
    }

    private static function trimOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /** http(s) only — avatar URLs are rendered as <img src>, so reject other schemes. */
    private static function isHttpUrl(string $url): bool
    {
        if (mb_strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return $scheme === 'http' || $scheme === 'https';
    }
}
