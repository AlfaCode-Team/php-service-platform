<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Retry;

/** Exponential backoff: base * 2^(attempt-1), capped, with optional jitter. */
final class ExponentialRetryStrategy implements RetryStrategyContract
{
    public function __construct(
        private readonly int  $baseSeconds = 5,
        private readonly int  $capSeconds  = 3600,
        private readonly bool $jitter      = true,
    ) {}

    public function delayFor(int $attempt): int
    {
        $delay = $this->baseSeconds * (2 ** max(0, $attempt - 1));
        $delay = min($delay, $this->capSeconds);
        if ($this->jitter) {
            $delay = random_int((int) ($delay / 2), $delay);
        }
        return $delay;
    }
}
