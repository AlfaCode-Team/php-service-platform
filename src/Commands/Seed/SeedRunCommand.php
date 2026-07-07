<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Seed;

use AlfaCode\LetMigrate\Exception\LetMigrateException;
use AlfacodeTeam\PhpIoCli\Depends\Colors;

/**
 * seed:run — execute all pending seeders in dependency order.
 *
 * Usage:
 *   seed:run
 *   seed:run --force      (re-run even if already seeded)
 *   seed:run --no-progress
 */
final class SeedRunCommand extends AbstractSeedCommand
{
    protected function configure(): void
    {
        $this->name        = 'seed:run';
        $this->description = 'Run all pending database seeders';

        $this->addOption('force',       'f', 'Re-run all seeders even if already seeded');
        $this->addOption('no-progress', 'q', 'Suppress spinner output');
    }

    protected function handle(): int
    {
        $force = $this->hasOption('force');

        $this->section('Database Seeders — Run');

        $spin = $this->spinner('Discovering seeders');
        $spin->start();

        $status  = $this->runner()->status();
        $pending = array_filter($status, static fn($s) => $s['status'] === 'pending');
        $count   = count($pending);

        $spin->stop($force ? count($status) . ' seeder(s) queued (--force)' : "{$count} pending seeder(s) found");

        if ($count === 0 && !$force) {
            $this->muted('All seeders are already up to date.');
            return self::SUCCESS;
        }

        if (!empty($status)) {
            $rows = [];
            foreach ($status as $name => $info) {
                $statusLabel = $info['status'] === 'run' && !$force
                    ? Colors::wrap('✔ run', Colors::GREEN)
                    : Colors::wrap('⟳ pending', Colors::YELLOW);
                $rows[] = [$statusLabel, $name, (string) ($info['batch'] ?? '—')];
            }

            $this->table()
                ->headers(['Status', 'Seeder', 'Batch'])
                ->rows($rows)
                ->style('compact')
                ->render();
            $this->newLine();
        }

        try {
            $runSpin = $this->spinner('Running seeders');
            $runSpin->start();

            $seeded = $this->runner()->run(force: $force);

            $runSpin->stop(count($seeded) . ' seeder(s) complete');
        } catch (LetMigrateException $e) {
            $this->alertError('Seeder failed', [$e->getMessage()]);
            return self::FAILURE;
        }

        $this->newLine();
        $this->alertSuccess(
            count($seeded) . ' seeder(s) executed successfully.',
            array_map(
                static fn(string $s) => Colors::wrap('✔', Colors::GREEN) . "  {$s}",
                $seeded,
            ),
        );

        return self::SUCCESS;
    }
}
