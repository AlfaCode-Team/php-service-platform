<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Retry;

/** Computes the delay (seconds) before the next retry of a failed job. */
interface RetryStrategyContract
{
    public function delayFor(int $attempt): int;
}
