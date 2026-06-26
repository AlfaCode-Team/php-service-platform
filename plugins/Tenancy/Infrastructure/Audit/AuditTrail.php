<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Audit;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Tenancy\Application\Ports\AuditSink;

/**
 * AuditTrail — append-only writer for the central `audit_log` table.
 *
 * Access rule: DatabasePort ONLY (central connection). Records identifiers +
 * structured meta only — never passwords/tokens/PII payloads. An audit write
 * must never break the action it records, so failures are swallowed (the trail
 * is best-effort; the security-critical path is the membership check, not the log).
 */
final class AuditTrail implements AuditSink
{
    public function __construct(
        private readonly DatabasePort $central,
    ) {}

    public function record(
        string $action,
        ?string $userId = null,
        ?string $tenantId = null,
        array $meta = [],
        ?string $ip = null,
    ): void {
        try {
            $this->central->execute(
                'INSERT INTO audit_log (event_id, user_id, tenant_id, action, ip, meta, occurred_at)
                 VALUES (:eid, :uid, :tid, :action, :ip, :meta, :ts)',
                [
                    'eid'    => self::ulid(),
                    'uid'    => $userId,
                    'tid'    => $tenantId,
                    'action' => $action,
                    'ip'     => $ip,
                    'meta'   => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_SLASHES),
                    'ts'     => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (\Throwable) {
            // Best-effort: never let an audit write fail the action it records.
        }
    }

    private static function ulid(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $ms = (int) (microtime(true) * 1000);
        $time = '';
        for ($i = 0; $i < 10; $i++) {
            $time = $alphabet[$ms % 32] . $time;
            $ms = intdiv($ms, 32);
        }
        $rand = '';
        for ($i = 0; $i < 16; $i++) {
            $rand .= $alphabet[random_int(0, 31)];
        }

        return $time . $rand;
    }
}
