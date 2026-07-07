<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\User\Domain\Entities\UserNotificationPreferences;
use Plugins\User\Domain\Entities\UserPreferences;
use Plugins\User\Domain\Entities\UserPrivacySettings;
use Plugins\User\Domain\Entities\UserProfile;

/**
 * UserSettingsRepository — DatabasePort ONLY. One repository for the four
 * per-user, TENANT-scoped settings singletons (profile, preferences, privacy,
 * notifications). The injected port is the request's tenant connection, so every
 * row lands in the user's tenant database. All writes use the portable upsert on
 * the user_id key.
 *
 * Invariants: parameterised queries only; \PDOException is translated to
 * RepositoryException; exception context carries the user id only (no PII).
 */
final class UserSettingsRepository
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    // ── profile ───────────────────────────────────────────────────────────────

    public function findProfile(string $userId): ?UserProfile
    {
        $row = $this->one(
            'user_profiles',
            'user_id, first_name, last_name, avatar_url, timezone, locale, phone',
            $userId,
            'profile',
        );
        if ($row === null) {
            return null;
        }

        return UserProfile::reconstitute($row);
    }

    public function saveProfile(UserProfile $p): void
    {
        $this->save('user_profiles', [
            'user_id'    => $p->userId(),
            'first_name' => $p->firstName(),
            'last_name'  => $p->lastName(),
            'avatar_url' => $p->avatarUrl(),
            'timezone'   => $p->timezone(),
            'locale'     => $p->locale(),
            'phone'      => $p->phone(),
        ], $p->userId(), 'profile');
    }

    // ── preferences ─────────────────────────────────────────────────────────────

    public function findPreferences(string $userId): ?UserPreferences
    {
        $row = $this->one(
            'user_preferences',
            'user_id, language, currency, theme, reduce_motion, larger_text, high_contrast, screen_reader_hints',
            $userId,
            'preferences',
        );
        if ($row === null) {
            return null;
        }

        return UserPreferences::reconstitute($row);
    }

    public function savePreferences(UserPreferences $p): void
    {
        $this->save('user_preferences', [
            'user_id'             => $p->userId(),
            'language'            => $p->language(),
            'currency'            => $p->currency(),
            'theme'               => $p->theme()->value,
            'reduce_motion'       => (int) $p->reduceMotion(),
            'larger_text'         => (int) $p->largerText(),
            'high_contrast'       => (int) $p->highContrast(),
            'screen_reader_hints' => (int) $p->screenReaderHints(),
        ], $p->userId(), 'preferences');
    }

    // ── privacy ─────────────────────────────────────────────────────────────────

    public function findPrivacy(string $userId): ?UserPrivacySettings
    {
        $row = $this->one(
            'user_privacy_settings',
            'user_id, profile_visibility, show_phone, show_email, marketing_opt_in, analytics_opt_in',
            $userId,
            'privacy',
        );
        if ($row === null) {
            return null;
        }

        return UserPrivacySettings::reconstitute($row);
    }

    public function savePrivacy(UserPrivacySettings $s): void
    {
        $this->save('user_privacy_settings', [
            'user_id'            => $s->userId(),
            'profile_visibility' => $s->profileVisibility()->value,
            'show_phone'         => (int) $s->showPhone(),
            'show_email'         => (int) $s->showEmail(),
            'marketing_opt_in'   => (int) $s->marketingOptIn(),
            'analytics_opt_in'   => (int) $s->analyticsOptIn(),
        ], $s->userId(), 'privacy');
    }

    // ── notification preferences ──────────────────────────────────────────────────

    public function findNotifications(string $userId): ?UserNotificationPreferences
    {
        $columns = implode(', ', array_keys(UserNotificationPreferences::FLAG_DEFAULTS));
        $row     = $this->one('user_notification_preferences', 'user_id, ' . $columns, $userId, 'notification_preferences');
        if ($row === null) {
            return null;
        }

        // $row carries user_id + every flag column; the entity casts them.
        return UserNotificationPreferences::reconstitute($row);
    }

    public function saveNotifications(UserNotificationPreferences $p): void
    {
        $values = ['user_id' => $p->userId()];
        foreach ($p->flags() as $key => $on) {
            $values[$key] = (int) $on;
        }
        $this->save('user_notification_preferences', $values, $p->userId(), 'notification_preferences');
    }

    // ── shared helpers ───────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function one(string $table, string $columns, string $userId, string $label): ?array
    {
        try {
            return $this->db->queryOne(
                'SELECT ' . $columns . ' FROM ' . $table . ' WHERE user_id = :id LIMIT 1',
                ['id' => $userId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException("Failed to load {$label}.", layer: 'repository.user_settings', previous: $e);
        }
    }

    /** @param array<string,mixed> $values */
    private function save(string $table, array $values, string $userId, string $label): void
    {
        $values['updated_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            $this->db->upsert($table, $values, ['user_id']);
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to save {$label}.",
                layer: 'repository.user_settings',
                context: ['userId' => $userId],
                previous: $e,
            );
        }
    }
}
