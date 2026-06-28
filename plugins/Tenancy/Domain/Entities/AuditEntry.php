<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Entities;

/**
 * AuditEntry — read model for one `audit_log` row.
 *
 * Reconstituted from a DB row (never constructed by the domain). The append-only
 * trail records identifiers + structured meta only, so this carries no secrets.
 */
final readonly class AuditEntry
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public int $id,
        public string $eventId,
        public ?string $userId,
        public ?string $tenantId,
        public string $action,
        public ?string $ip,
        public array $meta,
        public string $occurredAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $metaRaw = $row['meta'] ?? null;
        $meta    = is_string($metaRaw) && $metaRaw !== ''
            ? (json_decode($metaRaw, true) ?: [])
            : [];

        return new self(
            id:         (int) $row['id'],
            eventId:    (string) $row['event_id'],
            userId:     isset($row['user_id']) ? (string) $row['user_id'] : null,
            tenantId:   isset($row['tenant_id']) ? (string) $row['tenant_id'] : null,
            action:     (string) $row['action'],
            ip:         isset($row['ip']) ? (string) $row['ip'] : null,
            meta:       is_array($meta) ? $meta : [],
            occurredAt: (string) $row['occurred_at'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'event_id'    => $this->eventId,
            'user_id'     => $this->userId,
            'tenant_id'   => $this->tenantId,
            'action'      => $this->action,
            'ip'          => $this->ip,
            'meta'        => $this->meta,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
