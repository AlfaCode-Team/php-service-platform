<?php

declare(strict_types=1);

namespace Plugins\RedisCache\Infrastructure;

/**
 * RedisConnection — thin lazy wrapper around the phpredis \Redis client
 * (GDA rewrite of the 0.3 Redis connection machinery, minus Predis/clustering).
 *
 * Connects on first use so a worker that never touches Redis pays nothing. A
 * key prefix namespaces all keys, letting many apps share one Redis instance.
 */
final class RedisConnection
{
    private ?\Redis $client = null;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        private readonly ?string $password = null,
        private readonly int $database = 0,
        private readonly string $prefix = '',
        private readonly float $timeout = 2.0,
        private readonly bool $persistent = false,
    ) {}

    public function client(): \Redis
    {
        if ($this->client instanceof \Redis) {
            return $this->client;
        }

        $client = new \Redis();

        // Persistent connections (pconnect) are reused across requests within
        // the same FPM/CLI worker — a big saving under PHP-FPM. The persistent
        // id pins the socket to this host/port/db so distinct configs don't
        // collide. NOTE: leave this OFF under OpenSwoole — a pooled socket shared
        // across coroutines is not safe; use a per-worker pool there instead.
        $connected = $this->persistent
            ? $client->pconnect($this->host, $this->port, $this->timeout, $this->persistentId())
            : $client->connect($this->host, $this->port, $this->timeout);

        if (!$connected) {
            throw new \RuntimeException("Redis: unable to connect to {$this->host}:{$this->port}.");
        }
        if ($this->password !== null && $this->password !== '') {
            $client->auth($this->password);
        }
        if ($this->database !== 0) {
            $client->select($this->database);
        }

        return $this->client = $client;
    }

    private function persistentId(): string
    {
        return 'psp_' . $this->host . '_' . $this->port . '_' . $this->database;
    }

    public function prefix(string $key): string
    {
        return $this->prefix . $key;
    }

    public function rawPrefix(): string
    {
        return $this->prefix;
    }
}
