<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;

use AlfaCode\LetMigrate\Support\EventfulRunTrait;
use AlfaCode\LetMigrate\Support\JsonResultPresenter;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
use AlfaCode\LetMigrate\DeployLock;
 
final class MigrateRollbackCommand extends LetMigrateCommand
{
    use EventfulRunTrait;
 
    protected function configureEvents(MigrationEventDispatcher $events): void
    {
        $this->attachProgressListeners($events, verb: 'Rolling back');
    }
 
    protected function configure(): void
    {
        $this->name        = 'migrate:rollback';
        $this->description = 'Roll back the last N migration steps';
 
        $this->registerCommonOptions();
        $this->addOption('steps', 's',  'Steps to roll back',
            acceptsValue: true, default: '1');
        $this->addOption('force', 'f',  'Skip the interactive confirmation');
        $this->addOption('lock',  'l', 'Hold an advisory deploy lock');
        $this->addOption('lock-timeout', 'lt',
            'Seconds to wait for the lock', acceptsValue: true, default: '10');
    }
 
    protected function handle(): int
    {
        $steps = max(1, (int) $this->option('steps', '1'));
 
        if (!$this->hasOption('force') && !$this->wantsJson()) {
            $proceed = $this->confirm(
                "Roll back the last {$steps} migration(s)? This is destructive.",
                false,
            );
            if (!$proceed) {
                $this->muted('Cancelled.');
                return self::SUCCESS;
            }
        }
 
        $rollback = fn() => $this->service()->rollback($steps);
        $this->startProgress($steps, "Rolling back {$steps} step(s)");
 
        try {
            $result = $this->hasOption('lock')
                ? (new DeployLock(
                    $this->service()->driver(),
                    ($this->config()['prefix'] ?? '') . 'let_migrate_deploy',
                ))->withLock($rollback, (int) $this->option('lock-timeout', '10'))
                : $rollback();
        } catch (\RuntimeException $e) {
            $this->finishProgress('Aborted');
            $this->error($e->getMessage());
            return self::FAILURE;
        }
 
        $this->finishProgress('Rollback complete');
 
        if ($this->wantsJson()) {
            $this->emitJson((new JsonResultPresenter())->resultData($result));
            return self::SUCCESS;
        }
 
        if ($result->isEmpty()) {
            $this->info('Nothing to roll back.');
            return self::SUCCESS;
        }
 
        $rows = array_map(static fn($m) => [$m, 'rolled back'], $result->rolledBack);
        $this->table()->headers(['Migration', 'Status'])->rows($rows)->render();
 
        $this->alertWarning(
            'Migrations rolled back',
            ["Count: {$result->rolledBackCount()}"],
        );
 
        return self::SUCCESS;
    }
}
 