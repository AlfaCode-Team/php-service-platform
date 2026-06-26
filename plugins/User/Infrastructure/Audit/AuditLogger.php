<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Audit;

/**
 * Minimal, dependency-free security audit log.
 *
 * Writes one structured JSON line per security-relevant action (register,
 * delete, failed login, lockout, password rehash, email verification). Records
 * IDENTIFIERS and outcomes only — never passwords, hashes, or raw email/PII —
 * so the audit trail is safe to ship to a SIEM.
 *
 * Routed through error_log() (tagged source=user_audit) so it lands in the same
 * stream the platform already collects; swap for a dedicated LoggerPort if one
 * is introduced.
 */
final class AuditLogger
{
    /** @var callable(string):void */
    private $sink;

    /**
     * @param (callable(string):void)|null $sink Where to write each JSON line.
     *        Defaults to error_log(); inject a custom sink (file/SIEM) or a
     *        no-op in tests.
     */
    public function __construct(
        private readonly ?string $actorId = null,
        ?callable $sink = null,
    ) {
        $this->sink = $sink ?? static fn(string $line) => error_log($line);
    }

    /** @param array<string,scalar|null> $context */
    public function record(string $action, array $context = []): void
    {
        $entry = json_encode([
            'source'    => 'user_audit',
            'action'    => $action,
            'actor'     => $this->actorId,
            'context'   => $context,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
        ], JSON_UNESCAPED_SLASHES);

        if ($entry !== false) {
            ($this->sink)($entry);
        }
    }
}
