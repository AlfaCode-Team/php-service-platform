<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Seed;

use AlfaCode\LetMigrate\Exception\LetMigrateException;
use AlfacodeTeam\PhpIoCli\Depends\Colors;

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
