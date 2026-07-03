<?php

declare(strict_types=1);

namespace Plugins\User\Domain\Entities;

use Project\Support\Entity\Entity;

/**
 * UserNotificationPreferences — mirrors the TENANT-scoped
 * `user_notification_preferences` table (one row per user).
 *
 * The (channel × topic) matrix is modelled as a fixed map keyed by the column
 * name. FLAG_DEFAULTS is the single source of truth for BOTH the allowed keys
 * and their defaults — the DTO and repository iterate it, so adding a flag means
 * touching one place (plus a migration). Unknown keys are rejected.
 *
 * Built on the shared {@see Entity} attribute-bag base: each flag is a bag key
 * with an `int-bool` cast (0/1 in the DB, native bool in PHP).
 */
final class UserNotificationPreferences extends Entity
{
    /** Canonical column => default. Mirrors the migration defaults exactly. */
    public const FLAG_DEFAULTS = [
        'push_messages'   => true,  'push_bookings'   => true,  'push_payments'   => true,
        'push_reminders'  => true,  'push_promotions' => false, 'push_security'   => true,
        'email_messages'  => false, 'email_bookings'  => true,  'email_payments'  => true,
        'email_reminders' => false, 'email_promotions'=> false, 'email_security'  => true,
        'sms_messages'    => false, 'sms_bookings'    => true,  'sms_payments'    => true,
        'sms_reminders'   => false, 'sms_promotions'  => false, 'sms_security'    => true,
    ];

    protected string $primaryKey = 'user_id';

    /** Every flag column round-trips through `int-bool` (0/1 in DB, bool in PHP). */
    /** @var array<string, string> */
    protected array $casts = [
        'push_messages'   => 'int-bool', 'push_bookings'   => 'int-bool', 'push_payments'   => 'int-bool',
        'push_reminders'  => 'int-bool', 'push_promotions' => 'int-bool', 'push_security'   => 'int-bool',
        'email_messages'  => 'int-bool', 'email_bookings'  => 'int-bool', 'email_payments'  => 'int-bool',
        'email_reminders' => 'int-bool', 'email_promotions'=> 'int-bool', 'email_security'  => 'int-bool',
        'sms_messages'    => 'int-bool', 'sms_bookings'    => 'int-bool', 'sms_payments'    => 'int-bool',
        'sms_reminders'   => 'int-bool', 'sms_promotions'  => 'int-bool', 'sms_security'    => 'int-bool',
    ];

    /** @param array<string,bool> $flags */
    public static function fromInput(string $userId, array $flags): self
    {
        if ($userId === '' || mb_strlen($userId) > 31) {
            throw new \DomainException('UserNotificationPreferences requires a valid user id.');
        }

        // Start from defaults and apply only known flags — unknown keys throw.
        $resolved = self::FLAG_DEFAULTS;
        foreach ($flags as $key => $value) {
            if (!array_key_exists($key, self::FLAG_DEFAULTS)) {
                throw new \DomainException("Unknown notification flag: {$key}.");
            }
            $resolved[$key] = (bool) $value;
        }

        $p = (new self())->forceFill(['user_id' => $userId, ...$resolved]);
        $p->syncOriginal();

        return $p;
    }

    public static function defaults(string $userId): self
    {
        return self::fromInput($userId, []);
    }

    public function userId(): string { return $this->getString('user_id'); }

    public function isEnabled(string $flag): bool
    {
        return array_key_exists($flag, self::FLAG_DEFAULTS) && $this->getBool($flag);
    }

    /** @return array<string,bool> */
    public function flags(): array
    {
        $flags = [];
        foreach (array_keys(self::FLAG_DEFAULTS) as $key) {
            $flags[$key] = $this->getBool($key);
        }

        return $flags;
    }

    /**
     * Nests the flat "channel_topic" flags into { channel: { topic: bool } } for
     * the API. @return array<string, mixed>
     */
    public function toArray(bool $onlyChanged = false): array
    {
        $nested = [];
        foreach ($this->flags() as $key => $value) {
            [$channel, $topic] = explode('_', $key, 2);
            $nested[$channel][$topic] = $value;
        }

        return ['userId' => $this->userId(), 'flags' => $nested];
    }
}
