<?php

declare(strict_types=1);

namespace Project\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;

/**
 * FileQueue — a dependency-free, cross-process QueuePort adapter.
 *
 * Jobs are appended as JSON lines to one file per queue under var/queue/. Unlike
 * an in-memory queue, this survives between processes, so a job pushed by a web
 * request can be popped by a separate `php app/worker/run.php` process — enough
 * to run the worker end-to-end without Redis/SQS. Swap for RedisQueueAdapter in
 * production (it overrides this when REDIS_HOST is set).
 *
 * Not for high throughput: writes take an exclusive lock and pop rewrites the
 * file. It is a correct, simple default — not a broker.
 *
 * QueuePort has no pop(); the project's worker puller calls {@see pop()} on this
 * concrete adapter and rebuilds a JobPayload from the record.
 */
final class FileQueue implements QueuePort
{
    public function __construct(
        private readonly string $dir,
        private readonly int $defaultMaxAttempts = 3,
    ) {
    }

    public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string
    {
        $jobId = bin2hex(random_bytes(8));

        $record = [
            'jobId'       => $jobId,
            'jobClass'    => $jobClass,
            'data'        => $payload,
            'queue'       => $queue,
            'attempts'    => 0,
            'maxAttempts' => $this->defaultMaxAttempts,
            'enqueuedAt'  => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'availableAt' => time() + max(0, $delay),
        ];

        $this->append($queue, $record);

        return $jobId;
    }

    public function later(int $seconds, string $jobClass, array $payload, string $queue = 'default'): string
    {
        return $this->push($jobClass, $payload, $queue, $seconds);
    }

    public function size(string $queue = 'default'): int
    {
        $file = $this->file($queue);

        if (!is_file($file)) {
            return 0;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? 0 : count($lines);
    }

    /**
     * Pop the next due record (FIFO), or null when the queue is empty. Rewrites
     * the file without the popped line under an exclusive lock.
     *
     * @return array<string, mixed>|null
     */
    public function pop(string $queue = 'default'): ?array
    {
        $file = $this->file($queue);

        if (!is_file($file)) {
            return null;
        }

        $handle = fopen($file, 'c+');
        if ($handle === false) {
            return null;
        }

        try {
            flock($handle, LOCK_EX);

            $contents = stream_get_contents($handle) ?: '';
            $lines = array_values(array_filter(explode("\n", $contents), static fn(string $l): bool => trim($l) !== ''));

            $now = time();
            foreach ($lines as $i => $line) {
                $record = json_decode($line, true);
                if (!is_array($record)) {
                    unset($lines[$i]);
                    continue;
                }
                if (($record['availableAt'] ?? 0) > $now) {
                    continue;   // delayed — not due yet
                }

                // Remove this line and rewrite.
                unset($lines[$i]);
                $this->rewrite($handle, $lines);

                return $record;
            }

            return null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $record */
    private function append(string $queue, array $record): void
    {
        $file = $this->file($queue);
        $line = json_encode($record, JSON_UNESCAPED_SLASHES) . "\n";

        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param resource     $handle
     * @param list<string> $lines
     */
    private function rewrite($handle, array $lines): void
    {
        ftruncate($handle, 0);
        rewind($handle);
        if ($lines !== []) {
            fwrite($handle, implode("\n", $lines) . "\n");
        }
    }

    private function file(string $queue): string
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create queue directory: {$this->dir}");
        }

        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $queue) ?? 'default';

        return rtrim($this->dir, '/') . '/' . $safe . '.jsonl';
    }
}
