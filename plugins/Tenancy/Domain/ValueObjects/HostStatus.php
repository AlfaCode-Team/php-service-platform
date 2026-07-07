<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\ValueObjects;

/**
 * HostStatus — ownership-verification state of a tenant host.
 *
 * Backed by the tinyint stored in central `tenant_hosts.status` so the enum maps
 * 1:1 onto persistence. Only a Verified host is routable: the Host identifier
 * (and registry) resolve a hostname to its tenant ONLY in this state, so an
 * unverified domain can never hijack traffic before its owner proves control.
 */
enum HostStatus: int
{
    case Pending  = 0;
    case Verified = 1;
    case Failed   = 2;

    public function isRoutable(): bool
    {
        return $this === self::Verified;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending  => 'pending',
            self::Verified => 'verified',
            self::Failed   => 'failed',
        };
    }
}
