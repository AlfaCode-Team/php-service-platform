<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\{JobPayload, JobResult};

/**
 * Every background job implements this contract.
 */
interface JobContract
{
    /**
     * Execute the job. Return JobResult::success() on completion.
     * Return JobResult::skipped($reason) to stop without retry.
     * Throw any Throwable to trigger the retry strategy.
     */
    public function handle(JobPayload $payload): JobResult;

    /**
     * Called after max retries are exhausted, before moving to dead-letter queue.
     * Use to mark the operation as permanently failed in the database.
     */
    public function failed(JobPayload $payload, \Throwable $e): void;
}
