<?php

declare(strict_types=1);

namespace Plugins\Settings\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Validation\Validator;

/**
 * OptionsDTO: contact form settings — mirrors `tenant_settings_contact`.
 */
final readonly class ContactSettingsDTO
{
    public function __construct(
        public string $tenantId,
        public ?string $formRecipients = null,
        public ?string $autoReplySubject = null,
        public ?string $autoReplyMessage = null,
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
            tenantId:         (string) $row['tenant_id'],
            formRecipients:   self::str($row['contact_form_recipients'] ?? null),
            autoReplySubject: self::str($row['contact_auto_reply_subject'] ?? null),
            autoReplyMessage: self::str($row['contact_auto_reply_message'] ?? null),
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
            'contact_form_recipients'    => 'nullable|string|max:255',
            'contact_auto_reply_subject' => 'nullable|string|max:255',
            'contact_auto_reply_message' => 'nullable|string',
        ])->validate();

        $d = $base;

        return new self(
            tenantId:         $base->tenantId,
            formRecipients:   $request->input('contact_form_recipients', $d->formRecipients),
            autoReplySubject: $request->input('contact_auto_reply_subject', $d->autoReplySubject),
            autoReplyMessage: $request->input('contact_auto_reply_message', $d->autoReplyMessage),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tenant_id'                  => $this->tenantId,
            'contact_form_recipients'    => $this->formRecipients,
            'contact_auto_reply_subject' => $this->autoReplySubject,
            'contact_auto_reply_message' => $this->autoReplyMessage,
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
