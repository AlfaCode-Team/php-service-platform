<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Tenancy\API\Contracts\TenantHostRegistryContract;

/**
 * TenantHostRegistry — reads the central `tenant_hosts` table.
 *
 * Access rule: DatabasePort ONLY (this is a repository). The injected
 * DatabasePort is the CENTRAL connection (the ConnectionManager default) — host
 * resolution must never run against a tenant database (that would be circular).
 *
 * Only a VERIFIED host (status = 1) resolves to a tenant; pending/failed hosts
 * are unknown. Hits and misses are cached with a short TTL so an unknown or
 * hostile Host header cannot storm the central DB.
 */
final class TenantHostRegistry implements TenantHostRegistryContract
{
    private const STATUS_VERIFIED = 1;
    private const MISS = '__tenancy_host_miss__';

    public function __construct(
        private readonly DatabasePort $central,
        private readonly CachePort $cache,
        private readonly int $ttl = 60,
    ) {}

    public function tenantForHost(string $hostname): ?string
    {
        $hostname = $this->normalise($hostname);
        if ($hostname === '') {
            return null;
        }

        $key = $this->key($hostname);

        $cached = $this->cache->get($key);
        if ($cached === self::MISS) {
            return null;
        }
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $row = $this->central->queryOne(
            'SELECT tenant_id
               FROM tenant_hosts
              WHERE hostname = :host
                AND status = :status
                AND deleted_at IS NULL',
            ['host' => $hostname, 'status' => self::STATUS_VERIFIED],
        );

        if ($row === null) {
            $this->cache->set($key, self::MISS, $this->ttl);
            return null;
        }

        $tenantId = (string) $row['tenant_id'];
        $this->cache->set($key, $tenantId, $this->ttl);

        return $tenantId;
    }

    public function forget(string $hostname): void
    {
        $this->cache->delete($this->key($this->normalise($hostname)));
    }

    private function normalise(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));
        $hostname = preg_replace('/:\d+$/', '', $hostname) ?? $hostname; // strip port
        return trim($hostname, '.');                                     // strip trailing dot
    }

    private function key(string $hostname): string
    {
        return 'tenancy:host:' . $hostname;
    }
}
