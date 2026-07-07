<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\DTOs;

/**
 * The DNS proof-of-ownership instructions handed back to the UI when a host is
 * registered (or re-queried while Pending). The tenant owner must publish ONE of
 * these records, then call the verify endpoint, which scans the live DNS for it.
 *
 *   TXT  <txtRecordName>   "<txtRecordValue>"
 *   — or, for an apex they cannot add a sub-label TXT to —
 *   TXT  <hostname>        "<txtRecordValue>"
 *
 * When an expected A target is configured, the verifier ALSO confirms the host
 * resolves to {@see $expectedIp}.
 */
final readonly class HostVerificationInstructions
{
    public function __construct(
        public string $hostname,
        public string $txtRecordName,
        public string $txtRecordValue,
        public ?string $expectedIp = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'hostname'    => $this->hostname,
            'dns_record'  => [
                'type'  => 'TXT',
                'name'  => $this->txtRecordName,
                'value' => $this->txtRecordValue,
                'ttl'   => 300,
            ],
            'expected_ip' => $this->expectedIp,
            'instructions' =>
                "Add a TXT record \"{$this->txtRecordName}\" with value "
                . "\"{$this->txtRecordValue}\" at your DNS provider, then verify.",
        ];
    }
}
