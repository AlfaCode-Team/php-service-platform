<?php

declare(strict_types=1);

namespace Plugins\Settings\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Validation\Validator;

/**
 * OptionsDTO: email transport, behaviour & templates — mirrors `tenant_settings_email`.
 */
final readonly class EmailSettingsDTO
{
    /**
     * @param array<int|string, mixed>|null $templates
     * @param array<int|string, mixed>|null $notifications
     */
    public function __construct(
        public string $tenantId,
        public string $provider = 'smtp',
        public ?string $smtpHost = null,
        public int $smtpPort = 587,
        public ?string $smtpUsername = null,
        public ?string $smtpPassword = null,
        public string $smtpEncryption = 'tls',
        public ?string $senderName = null,
        public ?string $senderEmail = null,
        public ?string $replyTo = null,
        public bool $replyToEnabled = false,
        public bool $testMode = false,
        public bool $bounceHandling = false,
        public bool $unsubscribeHeader = false,
        public bool $trackingEnabled = false,
        public bool $archiveEnabled = false,
        public int $batchSize = 100,
        public int $retryAttempts = 3,
        public int $rateLimit = 100,
        public ?string $footer = null,
        public ?array $templates = null,
        public ?array $notifications = null,
    ) {}

    /** Hard-coded defaults for a tenant with no stored row yet. */
    public static function defaults(string $tenantId): self
    {
        return new self($tenantId);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            tenantId:          (string) $row['tenant_id'],
            provider:          (string) ($row['email_provider'] ?? 'smtp'),
            smtpHost:          self::str($row['smtp_host'] ?? null),
            smtpPort:          (int) ($row['smtp_port'] ?? 587),
            smtpUsername:      self::str($row['smtp_username'] ?? null),
            smtpPassword:      self::str($row['smtp_password'] ?? null),
            smtpEncryption:    (string) ($row['smtp_encryption'] ?? 'tls'),
            senderName:        self::str($row['sender_name'] ?? null),
            senderEmail:       self::str($row['sender_email'] ?? null),
            replyTo:           self::str($row['email_reply_to'] ?? null),
            replyToEnabled:    (bool) ($row['email_reply_to_enabled'] ?? false),
            testMode:          (bool) ($row['email_test_mode'] ?? false),
            bounceHandling:    (bool) ($row['email_bounce_handling'] ?? false),
            unsubscribeHeader: (bool) ($row['email_unsubscribe_header'] ?? false),
            trackingEnabled:   (bool) ($row['email_tracking_enabled'] ?? false),
            archiveEnabled:    (bool) ($row['email_archive_enabled'] ?? false),
            batchSize:         (int) ($row['email_batch_size'] ?? 100),
            retryAttempts:     (int) ($row['email_retry_attempts'] ?? 3),
            rateLimit:         (int) ($row['email_rate_limit'] ?? 100),
            footer:            self::str($row['email_footer'] ?? null),
            templates:         self::json($row['email_templates'] ?? null),
            notifications:     self::json($row['email_notifications'] ?? null),
        );
    }

    /**
     * Build from request input merged over `$base` (the tenant's current stored
     * settings), so an absent field keeps its existing value — a partial update,
     * never a reset to defaults.
     */
    public static function fromRequest(Request $request, self $base): self
    {
        Validator::make($request->all(), [
            'email_provider'           => 'nullable|in:smtp,sendgrid,mailgun,ses,postmark',
            'smtp_host'                => 'nullable|string|max:128',
            'smtp_port'                => 'nullable|integer|between:1,65535',
            'smtp_username'            => 'nullable|string|max:191',
            'smtp_password'            => 'nullable|string|max:255',
            'smtp_encryption'          => 'nullable|in:none,ssl,tls',
            'sender_name'              => 'nullable|string|max:128',
            'sender_email'             => 'nullable|email|max:191',
            'email_reply_to'           => 'nullable|email|max:191',
            'email_reply_to_enabled'   => 'nullable|boolean',
            'email_test_mode'          => 'nullable|boolean',
            'email_bounce_handling'    => 'nullable|boolean',
            'email_unsubscribe_header' => 'nullable|boolean',
            'email_tracking_enabled'   => 'nullable|boolean',
            'email_archive_enabled'    => 'nullable|boolean',
            'email_batch_size'         => 'nullable|integer|between:1,65535',
            'email_retry_attempts'     => 'nullable|integer|between:0,255',
            'email_rate_limit'         => 'nullable|integer|between:0,65535',
            'email_footer'             => 'nullable|string',
            'email_templates'          => 'nullable|array',
            'email_notifications'      => 'nullable|array',
        ])->validate();

        $d = $base;

        return new self(
            tenantId:          $base->tenantId,
            provider:          $request->string('email_provider') ?: $d->provider,
            smtpHost:          $request->input('smtp_host', $d->smtpHost),
            smtpPort:          $request->has('smtp_port') ? $request->integer('smtp_port') : $d->smtpPort,
            smtpUsername:      $request->input('smtp_username', $d->smtpUsername),
            smtpPassword:      $request->input('smtp_password', $d->smtpPassword),
            smtpEncryption:    $request->string('smtp_encryption') ?: $d->smtpEncryption,
            senderName:        $request->input('sender_name', $d->senderName),
            senderEmail:       $request->input('sender_email', $d->senderEmail),
            replyTo:           $request->input('email_reply_to', $d->replyTo),
            replyToEnabled:    $request->has('email_reply_to_enabled') ? $request->boolean('email_reply_to_enabled') : $d->replyToEnabled,
            testMode:          $request->has('email_test_mode') ? $request->boolean('email_test_mode') : $d->testMode,
            bounceHandling:    $request->has('email_bounce_handling') ? $request->boolean('email_bounce_handling') : $d->bounceHandling,
            unsubscribeHeader: $request->has('email_unsubscribe_header') ? $request->boolean('email_unsubscribe_header') : $d->unsubscribeHeader,
            trackingEnabled:   $request->has('email_tracking_enabled') ? $request->boolean('email_tracking_enabled') : $d->trackingEnabled,
            archiveEnabled:    $request->has('email_archive_enabled') ? $request->boolean('email_archive_enabled') : $d->archiveEnabled,
            batchSize:         $request->has('email_batch_size') ? $request->integer('email_batch_size') : $d->batchSize,
            retryAttempts:     $request->has('email_retry_attempts') ? $request->integer('email_retry_attempts') : $d->retryAttempts,
            rateLimit:         $request->has('email_rate_limit') ? $request->integer('email_rate_limit') : $d->rateLimit,
            footer:            $request->input('email_footer', $d->footer),
            templates:         $request->input('email_templates', $d->templates),
            notifications:     $request->input('email_notifications', $d->notifications),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tenant_id'                => $this->tenantId,
            'email_provider'           => $this->provider,
            'smtp_host'                => $this->smtpHost,
            'smtp_port'                => $this->smtpPort,
            'smtp_username'            => $this->smtpUsername,
            'smtp_password'            => $this->smtpPassword,
            'smtp_encryption'          => $this->smtpEncryption,
            'sender_name'              => $this->senderName,
            'sender_email'             => $this->senderEmail,
            'email_reply_to'           => $this->replyTo,
            'email_reply_to_enabled'   => $this->replyToEnabled,
            'email_test_mode'          => $this->testMode,
            'email_bounce_handling'    => $this->bounceHandling,
            'email_unsubscribe_header' => $this->unsubscribeHeader,
            'email_tracking_enabled'   => $this->trackingEnabled,
            'email_archive_enabled'    => $this->archiveEnabled,
            'email_batch_size'         => $this->batchSize,
            'email_retry_attempts'     => $this->retryAttempts,
            'email_rate_limit'         => $this->rateLimit,
            'email_footer'             => $this->footer,
            'email_templates'          => $this->templates,
            'email_notifications'      => $this->notifications,
        ];
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        $row = $this->toArray();
        $row['email_reply_to_enabled']   = (int) $this->replyToEnabled;
        $row['email_test_mode']          = (int) $this->testMode;
        $row['email_bounce_handling']    = (int) $this->bounceHandling;
        $row['email_unsubscribe_header'] = (int) $this->unsubscribeHeader;
        $row['email_tracking_enabled']   = (int) $this->trackingEnabled;
        $row['email_archive_enabled']    = (int) $this->archiveEnabled;
        $row['email_templates']          = $this->templates     === null ? null : json_encode($this->templates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $row['email_notifications']      = $this->notifications  === null ? null : json_encode($this->notifications, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $row;
    }

    private static function str(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }

    /** @return array<int|string, mixed>|null */
    private static function json(mixed $v): ?array
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_array($v)) {
            return $v;
        }
        $decoded = json_decode((string) $v, true);

        return is_array($decoded) ? $decoded : null;
    }
}
