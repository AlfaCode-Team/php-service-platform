<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Retry;

/**
 * FixedRetryStrategy — constant delay between every retry attempt.
 *
 * delay = seconds (regardless of attempt number)
 *
 * Use when you need predictable, uniform spacing (e.g. 60s between each retry).
 */
final class FixedRetryStrategy implements RetryStrategyContract
{
    public function __construct(
        private readonly int $seconds = 60,
    ) {}

    public function delayFor(int $attempt): int
    {
        return $this->seconds;
    }
}
