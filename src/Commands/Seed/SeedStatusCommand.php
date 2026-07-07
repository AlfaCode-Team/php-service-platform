<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Seed;

use AlfacodeTeam\PhpIoCli\Depends\Colors;

/**
 * seed:status — display the run/pending status for every discovered seeder.
 *
 * Usage:
 *   seed:status
 */
final class SeedStatusCommand extends AbstractSeedCommand
{
    protected function configure(): void
    {
        $this->name        = 'seed:status';
        $this->description = 'Show the status of all discovered database seeders';
    }

    protected function handle(): int
    {
        $this->section('Database Seeders — Status');

        $status = $this->runner()->status();

        if (empty($status)) {
            $this->muted('No seeders discovered in configured paths.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($status as $name => $info) {
            $rows[] = [
                $info['status'] === 'run'
                    ? Colors::wrap('✔ Run', Colors::GREEN)
                    : Colors::wrap('⟳ Pending', Colors::YELLOW),
                $name,
                $info['batch'] !== null ? (string) $info['batch'] : '—',
                $info['seeded_at'] ?? '—',
            ];
        }

        $this->table()
            ->headers(['Status', 'Seeder', 'Batch', 'Seeded At'])
            ->rows($rows)
            ->style('box')
            ->render();

        $run     = count(array_filter($status, static fn($s) => $s['status'] === 'run'));
        $pending = count($status) - $run;

        $this->newLine();
        $this->muted("{$run} run · {$pending} pending · " . count($status) . ' total');

        return self::SUCCESS;
    }
}
