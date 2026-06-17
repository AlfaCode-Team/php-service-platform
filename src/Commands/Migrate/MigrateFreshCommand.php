<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;


use AlfaCode\LetMigrate\Support\EventfulRunTrait;
use AlfaCode\LetMigrate\Support\JsonResultPresenter;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;

final class MigrateFreshCommand extends LetMigrateCommand
{
    use EventfulRunTrait;
 
    protected function configureEvents(MigrationEventDispatcher $events): void
    {
        $this->attachProgressListeners($events, verb: 'Re-applying');
    }
 
    protected function configure(): void
    {
        $this->name        = 'migrate:fresh';
        $this->description = 'Drop ALL tables and re-run every migration (Laravel parity)';
 
        $this->registerCommonOptions();
        $this->addOption('seed',  '', 'Run seeders after migrating');
        $this->addOption('force', 'f',  'Skip the interactive confirmation');
    }
 
    protected function handle(): int
    {
        if (!$this->hasOption('force') && !$this->wantsJson()) {
            $this->alertError(
                'DROP every table in the database',
                ['This is irreversible. All data will be lost.'],
            );
            $confirm = $this->ask('Type the connection name to confirm');
            $expected = (string) ($this->option('connection') ?? 'default');
            if ($confirm !== $expected) {
                $this->muted('Cancelled — name did not match.');
                return self::SUCCESS;
            }
        }
 
        // fresh() takes no arguments — verified MigrationService::fresh() signature.
        // The --seed flag is handled at the command layer by chaining seed().
        $this->startProgress(count($this->service()->status()), 'Fresh database');
        $result = $this->service()->fresh();
        $this->finishProgress('Fresh complete');
 
        if ($this->hasOption('seed')) {
            try {
                $this->info('Running seeders…');
                $this->service()->seed();
            } catch (\Throwable $e) {
                $this->error('Migrations applied but seeding failed: ' . $e->getMessage());
                return self::FAILURE;
            }
        }
 
        if ($this->wantsJson()) {
            $this->emitJson((new JsonResultPresenter())->resultData($result));
            return self::SUCCESS;
        }
 
        $this->alertSuccess('Fresh database', [
            "Re-applied: {$result->appliedCount()} migration(s)",
            'Seeded: ' . ($this->hasOption('seed') ? 'yes' : 'no'),
        ]);
 
        return self::SUCCESS;
    }
}