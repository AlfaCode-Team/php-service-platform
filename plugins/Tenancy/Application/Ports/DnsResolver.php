<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Ports;

/**
 * DnsResolver — outbound DNS lookups for proof-of-ownership verification.
 *
 * This is the seam the verifier uses to SCAN a domain's live records. The
 * concrete adapter ({@see \Plugins\Tenancy\Infrastructure\Dns\SystemDnsResolver})
 * wraps PHP's resolver; a fake makes TenantHostService fully unit-testable
 * offline. Implementations MUST never throw — a lookup failure returns an empty
 * list so verification simply fails closed (host stays unverified).
 */
interface DnsResolver
{
    /**
     * TXT record values at $name (already unquoted/concatenated).
     *
     * @return string[]
     */
    public function txt(string $name): array;

    /**
     * IPv4 + IPv6 addresses the host resolves to (A + AAAA).
     *
     * @return string[]
     */
    public function ips(string $hostname): array;
}
