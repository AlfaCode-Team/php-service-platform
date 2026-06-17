<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker;

// ─── JobPayload ───────────────────────────────────────────────────────────────

final readonly class JobPayload
{
    public function __construct(
        private string             $jobId,
        private string             $jobClass,
        private array              $data,
        private string             $queue,
        private int                $attempts,
        private int                $maxAttempts,
        private \DateTimeImmutable $enqueuedAt,
        private string             $signature,
    ) {}

    public function jobId(): string              { return $this->jobId; }
    public function jobClass(): string           { return $this->jobClass; }
    public function data(): array                { return $this->data; }
    public function queue(): string              { return $this->queue; }
    public function attempts(): int              { return $this->attempts; }
    public function maxAttempts(): int           { return $this->maxAttempts; }
    public function enqueuedAt(): \DateTimeImmutable { return $this->enqueuedAt; }
    public function signature(): string          { return $this->signature; }

    public function isSignatureValid(string $secret): bool
    {
        $expected = hash_hmac('sha256', json_encode($this->data), $secret);
        return hash_equals($expected, $this->signature);
    }

    public function hasExceededMaxAttempts(): bool
    {
        return $this->attempts >= $this->maxAttempts;
    }
}

