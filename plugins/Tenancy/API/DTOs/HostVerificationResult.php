<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\DTOs;

/**
 * Outcome of a verification attempt — what the DNS scan actually found, so the
 * UI can tell the owner exactly why a check failed (propagation lag, wrong
 * value, missing A record) instead of a bare "failed".
 */
final readonly class HostVerificationResult
{
    /**
     * @param string[] $foundTxt   TXT values observed at the challenge name
     * @param string[] $foundIps   A/AAAA addresses observed for the host
     */
    public function __construct(
        public string $hostname,
        public bool $verified,
        public string $status,
        public ?string $reason = null,
        public array $foundTxt = [],
        public array $foundIps = [],
    ) {}

    public static function ok(string $hostname, array $foundTxt, array $foundIps): self
    {
        return new self($hostname, true, 'verified', null, $foundTxt, $foundIps);
    }

    public static function fail(string $hostname, string $reason, array $foundTxt = [], array $foundIps = []): self
    {
        return new self($hostname, false, 'failed', $reason, $foundTxt, $foundIps);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'hostname'  => $this->hostname,
            'verified'  => $this->verified,
            'status'    => $this->status,
            'reason'    => $this->reason,
            'observed'  => [
                'txt' => $this->foundTxt,
                'ips' => $this->foundIps,
            ],
        ];
    }
}
