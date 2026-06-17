<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Seed;

use AlfaCode\LetMigrate\Exception\LetMigrateException;
use AlfaCode\LetMigrate\Seeder\SeederRunner;
use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpIoCli\Depends\Colors;

/**
 * Shared base for seeder commands.
 * Mirrors the pattern used by AbstractMigrateCommand.
 */
abstract class AbstractSeedCommand extends AbstractCommand
{
    private SeederRunner $seederRunner;

    final public function withRunner(SeederRunner $runner): static
    {
        $this->seederRunner = $runner;
        return $this;
    }

    final protected function runner(): SeederRunner
    {
        if (!isset($this->seederRunner)) {
            throw new \LogicException(
                static::class . ' requires a SeederRunner injected via withRunner().',
            );
        }
        return $this->seederRunner;
    }
}

// ─────────────────────────────────────────────────────────────────────────────

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

// ─────────────────────────────────────────────────────────────────────────────

/**
 * seed:fresh — clear seeder history and re-run every seeder.
 *
 * Usage:
 *   seed:fresh
 *   seed:fresh --force   (skip confirmation)
 */
final class SeedFreshCommand extends AbstractSeedCommand
{
    protected function configure(): void
    {
        $this->name        = 'seed:fresh';
        $this->description = 'Clear seeder history and re-run all seeders';

        $this->addOption('force', 'f', 'Skip confirmation');
    }

    protected function handle(): int
    {
        $force = $this->hasOption('force');

        $this->section('Database Seeders — Fresh');

        if (!$force) {
            $this->alertWarning(
                'This will clear all seeder history and re-run every seeder.',
                ['Existing data inserted by seeders will be duplicated unless seeders handle idempotency.'],
            );
            $this->newLine();

            $confirmed = $this->confirm('Continue?', default: false);

            if (!$confirmed) {
                $this->muted('Aborted.');
                return self::SUCCESS;
            }
        }

        try {
            $spin = $this->spinner('Running fresh seed');
            $spin->start();

            $seeded = $this->runner()->fresh();

            $spin->stop(count($seeded) . ' seeder(s) complete');
        } catch (LetMigrateException $e) {
            $this->alertError('Fresh seed failed', [$e->getMessage()]);
            return self::FAILURE;
        }

        $this->newLine();
        $this->alertSuccess(
            'Fresh seed complete — ' . count($seeded) . ' seeder(s) executed.',
            array_map(static fn(string $s) => Colors::wrap('✔', Colors::GREEN) . "  {$s}", $seeded),
        );

        return self::SUCCESS;
    }
}

// ─────────────────────────────────────────────────────────────────────────────

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
