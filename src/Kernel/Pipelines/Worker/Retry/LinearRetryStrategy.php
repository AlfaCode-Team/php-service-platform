<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Retry;

/**
 * LinearRetryStrategy — delay grows linearly with each attempt.
 *
 * delay = min(base * attempt, cap)
 *
 * Example (base=30, cap=300): 30s, 60s, 90s, 120s, … 300s, 300s, …
 */
final class LinearRetryStrategy implements RetryStrategyContract
{
    public function __construct(
        private readonly int $baseSeconds = 30,
        private readonly int $capSeconds  = 600,
    ) {}

    public function delayFor(int $attempt): int
    {
        return min($this->baseSeconds * $attempt, $this->capSeconds);
    }
}
