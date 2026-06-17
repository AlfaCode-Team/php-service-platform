<?php

declare(strict_types=1);

namespace Plugins\Session\Infrastructure\Handlers;

/**
 * In-memory session handler (GDA rewrite of the 0.3 ArraySessionHandler).
 *
 * Data lives only for the lifetime of the process, so it is ideal for tests,
 * CLI runs, and stateless contexts where persistence is undesirable. Implements
 * the native \SessionHandlerInterface for interchangeability.
 */
final class ArraySessionHandler implements \SessionHandlerInterface
{
    /** @var array<string, array{data: string, time: int}> */
    private array $store = [];

    public function __construct(private readonly int $lifetime = 7200) {}

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $entry = $this->store[$id] ?? null;
        if ($entry === null || $entry['time'] + $this->lifetime < time()) {
            return '';
        }
        return $entry['data'];
    }

    public function write(string $id, string $data): bool
    {
        $this->store[$id] = ['data' => $data, 'time' => time()];
        return true;
    }

    public function destroy(string $id): bool
    {
        unset($this->store[$id]);
        return true;
    }

    public function gc(int $maxLifetime): int|false
    {
        $deleted = 0;
        foreach ($this->store as $id => $entry) {
            if ($entry['time'] + $maxLifetime < time()) {
                unset($this->store[$id]);
                $deleted++;
            }
        }
        return $deleted;
    }
}
