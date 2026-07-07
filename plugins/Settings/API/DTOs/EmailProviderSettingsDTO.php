<?php

declare(strict_types=1);

namespace Plugins\Settings\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Validation\Validator;

/**
 * OptionsDTO: third-party email provider credentials — mirrors
 * `tenant_settings_email_providers`. Loaded only when provider != smtp.
 */
final readonly class EmailProviderSettingsDTO
{
    public function __construct(
        public string $tenantId,
        public ?string $sendgridApiKey = null,
        public ?string $mailgunDomain = null,
        public ?string $mailgunApiKey = null,
        public ?string $mailgunRegion = null,
        public ?string $postmarkServerToken = null,
        public ?string $awsAccessKeyId = null,
        public ?string $awsSecretAccessKey = null,
        public ?string $awsRegion = null,
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
            tenantId:            (string) $row['tenant_id'],
            sendgridApiKey:      self::str($row['sendgrid_api_key'] ?? null),
            mailgunDomain:       self::str($row['mailgun_domain'] ?? null),
            mailgunApiKey:       self::str($row['mailgun_api_key'] ?? null),
            mailgunRegion:       self::str($row['mailgun_region'] ?? null),
            postmarkServerToken: self::str($row['postmark_server_token'] ?? null),
            awsAccessKeyId:      self::str($row['aws_access_key_id'] ?? null),
            awsSecretAccessKey:  self::str($row['aws_secret_access_key'] ?? null),
            awsRegion:           self::str($row['aws_region'] ?? null),
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
            'sendgrid_api_key'      => 'nullable|string|max:255',
            'mailgun_domain'        => 'nullable|string|max:191',
            'mailgun_api_key'       => 'nullable|string|max:255',
            'mailgun_region'        => 'nullable|string|max:32',
            'postmark_server_token' => 'nullable|string|max:255',
            'aws_access_key_id'     => 'nullable|string|max:128',
            'aws_secret_access_key' => 'nullable|string|max:255',
            'aws_region'            => 'nullable|string|max:32',
        ])->validate();

        $d = $base;

        return new self(
            tenantId:            $base->tenantId,
            sendgridApiKey:      $request->input('sendgrid_api_key', $d->sendgridApiKey),
            mailgunDomain:       $request->input('mailgun_domain', $d->mailgunDomain),
            mailgunApiKey:       $request->input('mailgun_api_key', $d->mailgunApiKey),
            mailgunRegion:       $request->input('mailgun_region', $d->mailgunRegion),
            postmarkServerToken: $request->input('postmark_server_token', $d->postmarkServerToken),
            awsAccessKeyId:      $request->input('aws_access_key_id', $d->awsAccessKeyId),
            awsSecretAccessKey:  $request->input('aws_secret_access_key', $d->awsSecretAccessKey),
            awsRegion:           $request->input('aws_region', $d->awsRegion),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tenant_id'             => $this->tenantId,
            'sendgrid_api_key'      => $this->sendgridApiKey,
            'mailgun_domain'        => $this->mailgunDomain,
            'mailgun_api_key'       => $this->mailgunApiKey,
            'mailgun_region'        => $this->mailgunRegion,
            'postmark_server_token' => $this->postmarkServerToken,
            'aws_access_key_id'     => $this->awsAccessKeyId,
            'aws_secret_access_key' => $this->awsSecretAccessKey,
            'aws_region'            => $this->awsRegion,
        ];
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return $this->toArray();
    }

    private static function str(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }
}
