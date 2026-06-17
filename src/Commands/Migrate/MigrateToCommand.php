<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;

use AlfaCode\LetMigrate\Support\EventfulRunTrait;
use AlfaCode\LetMigrate\Support\JsonResultPresenter;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
use AlfacodeTeam\PhpIoCli\Components\Select;

final class MigrateToCommand extends LetMigrateCommand
{
    use EventfulRunTrait;
 
    protected function configureEvents(MigrationEventDispatcher $events): void
    {
        $this->attachProgressListeners($events, verb: 'Migrating');
    }
 
    protected function configure(): void
    {
        $this->name        = 'migrate:to';
        $this->description = 'Run pending migrations up to (and including) a target';
 
        $this->addArgument('target', 'Target migration filename');
        $this->registerCommonOptions();
    }
 
    protected function handle(): int
    {
        $target  = (string) ($this->argument('target') ?? '');
        $pending = $this->service()->pending();
 
        // No target given — show an interactive picker of pending migrations.
        if ($target === '') {
            if ($pending === []) {
                $this->success('Already up to date.');
                return self::SUCCESS;
            }
 
            $picked = (new Select(
                'Migrate up to which file?',
                array_keys($pending),
            ))->run();
 
            if (!is_string($picked) || $picked === '') {
                $this->muted('No target selected.');
                return self::SUCCESS;
            }
 
            $target = $picked;
        }
 
        // Bar covers from current applied → target (estimate via pending size).
        $this->startProgress(count($pending), "Migrating to {$target}");
        $result = $this->service()->migrateTo($target);
        $this->finishProgress("Reached {$target}");
 
        if ($this->wantsJson()) {
            $this->emitJson((new JsonResultPresenter())->resultData($result));
            return self::SUCCESS;
        }
 
        $this->alertSuccess("Migrated up to {$target}",
            ["Applied: {$result->appliedCount()}"]);
 
        return self::SUCCESS;
    }
}
 