<?php

declare(strict_types=1);

namespace Plugins\RedisCache\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;

/**
 * Redis-backed QueuePort adapter (GDA rewrite of the 0.3 Redis queue layer).
 *
 * Immediate jobs are pushed onto a Redis list (LPUSH); the worker pulls with
 * RPOP/BRPOP. Delayed jobs go onto a per-queue sorted set scored by their ready
 * timestamp — the WorkerPipeline promotes due jobs onto the list when it drains
 * (promoteDue() is exposed for that puller). Each job carries a generated id.
 */
final class RedisQueueAdapter implements QueuePort
{
    public function __construct(private readonly RedisConnection $connection) {}

    public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string
    {
        return $delay > 0
            ? $this->later($delay, $jobClass, $payload, $queue)
            : $this->dispatch($jobClass, $payload, $queue, readyAt: null);
    }

    public function later(int $seconds, string $jobClass, array $payload, string $queue = 'default'): string
    {
        return $this->dispatch($jobClass, $payload, $queue, readyAt: time() + max(0, $seconds));
    }

    public function size(string $queue = 'default'): int
    {
        $client = $this->connection->client();
        $ready  = (int) $client->lLen($this->listKey($queue));
        $delayed = (int) $client->zCard($this->delayedKey($queue));
        return $ready + $delayed;
    }

    /**
     * Move any delayed jobs that are now due onto the ready list. Returns the
     * count promoted. Intended to be called by the worker puller each tick.
     */
    public function promoteDue(string $queue = 'default'): int
    {
        $client    = $this->connection->client();
        $delayedKey = $this->delayedKey($queue);
        $due = $client->zRangeByScore($delayedKey, '-inf', (string) time());
        if ($due === false || $due === []) {
            return 0;
        }
        $promoted = 0;
        foreach ($due as $job) {
            // Remove first so a concurrent worker cannot double-promote.
            if ((int) $client->zRem($delayedKey, $job) === 1) {
                $client->lPush($this->listKey($queue), $job);
                $promoted++;
            }
        }
        return $promoted;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatch(string $jobClass, array $payload, string $queue, ?int $readyAt): string
    {
        $id  = bin2hex(random_bytes(16));
        $env = json_encode([
            'id'       => $id,
            'jobClass' => $jobClass,
            'payload'  => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        $client = $this->connection->client();
        if ($readyAt === null) {
            $client->lPush($this->listKey($queue), $env);
        } else {
            $client->zAdd($this->delayedKey($queue), $readyAt, $env);
        }
        return $id;
    }

    private function listKey(string $queue): string
    {
        return $this->connection->prefix('queue:' . $queue);
    }

    private function delayedKey(string $queue): string
    {
        return $this->connection->prefix('queue:' . $queue . ':delayed');
    }
}
