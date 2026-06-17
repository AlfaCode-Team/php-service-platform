<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;

/**
 * migrate:install — bootstrap the migration tracking table.
 *
 * LetMigrate uses a tracking table (default: `let_migrations`) to record
 * which migrations have been applied. This command creates that table if
 * it does not already exist. All other migrate:* commands call ensureTable()
 * automatically, but running this explicitly is useful in:
 *   • CI pipelines where you want to separate "setup" from "run"
 *   • Fresh environment bootstraps
 *   • Debugging connectivity before any migrations run
 *
 * Usage:
 *   migrate:install
 *   migrate:install --force   (suppress the "already exists" notice)
 */

final class MigrateInstallCommand extends LetMigrateCommand
{
    protected function configure(): void
    {
        $this->name        = 'migrate:install';
        $this->description = 'Create the migration tracking table (safe to re-run)';
        $this->addOption('force', 'f', 'Suppress the "already installed" notice (useful in scripts)');
 
        $this->registerCommonOptions(withJson: false);
    }
 
    protected function handle(): int
    {
       
        $force = $this->hasOption('force');

        $this->section('Database Migrations — Install');

        // ── 1. Check whether the table already exists ─────────────
        $spin = $this->spinner('Checking migration tracking table');
        $spin->start();

        $alreadyInstalled = $this->service()->isInstalled();

        $spin->stop($alreadyInstalled ? 'Table already exists' : 'Table not found — will create');

        // ── 2. Already installed ──────────────────────────────────
        if ($alreadyInstalled) {
            if (!$force) {
                $this->alertInfo(
                    'Already installed',
                    ['The migration tracking table already exists — nothing to do.'],
                );
            }

            return self::SUCCESS;
        }

        // ── 3. Create the table ───────────────────────────────────

        $createSpin = $this->spinner('Creating migration tracking table');
        $createSpin->start();

        try {
            $this->service()->install();
            $createSpin->stop('Table created successfully');
        } catch (\Throwable $e) {
            $createSpin->stop('Failed');
            $this->alertError('Install failed', [
                $e->getMessage(),
                'Check your database connection and permissions.',
            ]);

            return self::FAILURE;
        }

        // ── 4. Confirm ────────────────────────────────────────────
        $this->alertSuccess('Migration tracking table installed!', [
            'You can now run migrate:run to apply your migrations.',
        ]);

        return self::SUCCESS;
    }
}
 