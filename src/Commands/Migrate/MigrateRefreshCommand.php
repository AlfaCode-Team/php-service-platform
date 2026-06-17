<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;
 
use AlfaCode\LetMigrate\Support\EventfulRunTrait;
use AlfaCode\LetMigrate\Support\JsonResultPresenter;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
 
final class MigrateRefreshCommand extends LetMigrateCommand
{
    use EventfulRunTrait;
 
    protected function configureEvents(MigrationEventDispatcher $events): void
    {
        $this->attachProgressListeners($events, verb: 'Refreshing');
    }
 
    protected function configure(): void
    {
        $this->name        = 'migrate:refresh';
        $this->description = 'Reset and re-run every migration';
 
        $this->registerCommonOptions();
        $this->addOption('force', 'f', 'Skip the interactive confirmation');
    }
 
    protected function handle(): int
    {
        if (!$this->hasOption('force') && !$this->wantsJson()) {
            $this->alertWarning('This will reset and re-run EVERY migration.');
            if (!$this->confirm('Continue?', false)) {
                $this->muted('Cancelled.');
                return self::SUCCESS;
            }
        }
 
        // refresh = reset + run, so the bar tracks the down + up passes.
        $total = count($this->service()->status());
        $this->startProgress(max(1, $total * 2), 'Refresh (reset + run)');
 
        $result = $this->service()->refresh();
        $this->finishProgress('Refresh complete');
 
        if ($this->wantsJson()) {
            $this->emitJson((new JsonResultPresenter())->resultData($result));
            return self::SUCCESS;
        }
 
        $this->alertSuccess('Refresh complete', [
            "Re-applied: {$result->appliedCount()} migration(s)",
            "Batch: {$result->batch}",
        ]);
 
        return self::SUCCESS;
    }
}
 