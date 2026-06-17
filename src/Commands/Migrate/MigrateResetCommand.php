<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;
 
use AlfaCode\LetMigrate\Support\EventfulRunTrait;
use AlfaCode\LetMigrate\Support\JsonResultPresenter;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
 
final class MigrateResetCommand extends LetMigrateCommand
{
    use EventfulRunTrait;
 
    protected function configureEvents(MigrationEventDispatcher $events): void
    {
        $this->attachProgressListeners($events, verb: 'Resetting');
    }
 
    protected function configure(): void
    {
        $this->name        = 'migrate:reset';
        $this->description = 'Roll back ALL applied migrations';
 
        $this->registerCommonOptions();
        $this->addOption('force', 'f', 'Skip the interactive confirmation');
    }
 
    protected function handle(): int
    {
        if (!$this->hasOption('force') && !$this->wantsJson()) {
            $this->alertWarning('This will roll back EVERY applied migration.');
            if (!$this->confirm('Are you absolutely sure?', false)) {
                $this->muted('Cancelled.');
                return self::SUCCESS;
            }
        }
 
        // Count applied migrations so the bar has a total to fill.
        $applied = array_filter($this->service()->status(),
            static fn($r) => ($r['status'] ?? '') === 'applied');
        $this->startProgress(count($applied), 'Resetting database');
 
        $result = $this->service()->reset();
        $this->finishProgress('Reset complete');
 
        if ($this->wantsJson()) {
            $this->emitJson((new JsonResultPresenter())->resultData($result));
            return self::SUCCESS;
        }
 
        $this->alertSuccess('Reset complete',
            ["Rolled back: {$result->rolledBackCount()} migration(s)"]);
 
        return self::SUCCESS;
    }
}
 