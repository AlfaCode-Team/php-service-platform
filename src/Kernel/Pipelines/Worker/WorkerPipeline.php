<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Contracts\JobContract;

/**
 * WorkerPipeline — job registry.
 *
 * Modules register jobs ONCE during boot():
 *   $worker->job('invoice.email', SendInvoiceEmailJob::class);
 *
 * WorkerLoop resolves and executes them per dequeued message.
 */
final class WorkerPipeline
{
    /** @var array<string, class-string<JobContract>> job name => handler class */
    private array $jobs = [];

    /** @param class-string<JobContract> $jobClass */
    public function job(string $name, string $jobClass): void
    {
        $this->jobs[$name] = $jobClass;
    }

    /** @return class-string<JobContract>|null */
    public function resolve(string $name): ?string
    {
        return $this->jobs[$name] ?? null;
    }

    /** @return array<string, class-string<JobContract>> */
    public function jobs(): array
    {
        return $this->jobs;
    }
}
