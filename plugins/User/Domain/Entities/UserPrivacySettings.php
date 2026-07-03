<?php

declare(strict_types=1);

namespace Plugins\User\Domain\Entities;

use Plugins\User\Domain\ValueObjects\ProfileVisibility;
use Project\Support\Entity\Entity;

/**
 * UserPrivacySettings — mirrors the TENANT-scoped `user_privacy_settings` table
 * (one row per user). Built on the shared {@see Entity} attribute-bag base,
 * keyed by DB column; boolean toggles use the `int-bool` cast.
 */
final class UserPrivacySettings extends Entity
{
    protected string $primaryKey = 'user_id';

    /** @var array<string, string> */
    protected array $casts = [
        'show_phone'       => 'int-bool',
        'show_email'       => 'int-bool',
        'marketing_opt_in' => 'int-bool',
        'analytics_opt_in' => 'int-bool',
    ];

    public static function fromInput(
        string $userId,
        ProfileVisibility $profileVisibility,
        bool $showPhone,
        bool $showEmail,
        bool $marketingOptIn,
        bool $analyticsOptIn,
    ): self {
        return self::guarded([
            'user_id'            => $userId,
            'profile_visibility' => $profileVisibility->value,
            'show_phone'         => $showPhone,
            'show_email'         => $showEmail,
            'marketing_opt_in'   => $marketingOptIn,
            'analytics_opt_in'   => $analyticsOptIn,
        ]);
    }

    public static function defaults(string $userId): self
    {
        // Mirrors the migration defaults: public, phone shown, email hidden,
        // marketing off, analytics on.
        return self::guarded([
            'user_id'            => $userId,
            'profile_visibility' => ProfileVisibility::Public->value,
            'show_phone'         => true,
            'show_email'         => false,
            'marketing_opt_in'   => false,
            'analytics_opt_in'   => true,
        ]);
    }

    /** @param array<string,mixed> $attrs Validate, then hydrate the bag. */
    private static function guarded(array $attrs): self
    {
        $userId = (string) $attrs['user_id'];
        if ($userId === '' || mb_strlen($userId) > 31) {
            throw new \DomainException('UserPrivacySettings requires a valid user id.');
        }
        // Validate the visibility is a known enum case.
        ProfileVisibility::from((string) $attrs['profile_visibility']);

        $s = (new self())->forceFill($attrs);
        $s->syncOriginal();

        return $s;
    }

    public function userId(): string                       { return $this->getString('user_id'); }
    public function profileVisibility(): ProfileVisibility { return ProfileVisibility::from($this->getString('profile_visibility')); }
    public function showPhone(): bool                      { return $this->getBool('show_phone'); }
    public function showEmail(): bool                      { return $this->getBool('show_email'); }
    public function marketingOptIn(): bool                 { return $this->getBool('marketing_opt_in'); }
    public function analyticsOptIn(): bool                 { return $this->getBool('analytics_opt_in'); }

    /** @return array<string, mixed> Camel-cased API shape (not the DB shape). */
    public function toArray(bool $onlyChanged = false): array
    {
        return [
            'userId'            => $this->userId(),
            'profileVisibility' => $this->profileVisibility()->value,
            'showPhone'         => $this->showPhone(),
            'showEmail'         => $this->showEmail(),
            'marketingOptIn'    => $this->marketingOptIn(),
            'analyticsOptIn'    => $this->analyticsOptIn(),
        ];
    }
}
