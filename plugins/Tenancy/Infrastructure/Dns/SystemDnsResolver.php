<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Dns;

use Plugins\Tenancy\Application\Ports\DnsResolver;

/**
 * SystemDnsResolver — scans live DNS records via PHP's resolver (`dns_get_record`).
 *
 * Access rule: this is the "gateway" for ownership verification — it talks to the
 * outside world (DNS) and translates a vendor failure into an EMPTY result rather
 * than letting it escape, so {@see \Plugins\Tenancy\Application\Services\TenantHostService}
 * fails closed (a lookup error simply means "not verified yet").
 *
 * Lookups are bounded and best-effort: no record found, a SERVFAIL, or a
 * suppressed warning all yield []. Results are NOT cached here — verification is
 * an explicit, low-frequency user action, and caching a negative DNS answer would
 * make propagation feel broken to the owner.
 */
final class SystemDnsResolver implements DnsResolver
{
    public function txt(string $name): array
    {
        $records = $this->lookup($name, DNS_TXT);

        $values = [];
        foreach ($records as $record) {
            // PHP exposes the joined string as 'txt' and the raw chunks as 'entries'.
            if (isset($record['txt']) && is_string($record['txt'])) {
                $values[] = $record['txt'];
            }
            if (isset($record['entries']) && is_array($record['entries'])) {
                $values[] = implode('', $record['entries']);
            }
        }

        return array_values(array_unique(array_filter($values, static fn (string $v): bool => $v !== '')));
    }

    public function ips(string $hostname): array
    {
        $ips = [];

        foreach ($this->lookup($hostname, DNS_A) as $record) {
            if (isset($record['ip'])) {
                $ips[] = (string) $record['ip'];
            }
        }
        foreach ($this->lookup($hostname, DNS_AAAA) as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = (string) $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lookup(string $name, int $type): array
    {
        $name = rtrim($name, '.');
        if ($name === '') {
            return [];
        }

        try {
            // Suppress the warning dns_get_record emits on SERVFAIL/timeout —
            // a failed lookup is an expected outcome, not an exception path.
            $records = @dns_get_record($name, $type);
        } catch (\Throwable) {
            return [];
        }

        return is_array($records) ? $records : [];
    }
}
