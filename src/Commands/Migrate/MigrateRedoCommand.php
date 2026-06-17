<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;

use AlfaCode\LetMigrate\Support\EventfulRunTrait;
use AlfaCode\LetMigrate\Support\JsonResultPresenter;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;

final class MigrateRedoCommand extends LetMigrateCommand
{
    use EventfulRunTrait;

    protected function configureEvents(MigrationEventDispatcher $events): void
    {
        $this->attachProgressListeners($events, verb: 'Redo');
    }

    protected function configure(): void
    {
        $this->name        = 'migrate:redo';
        $this->description = 'Roll back N migrations and re-apply them';

        $this->registerCommonOptions();
        $this->addOption('steps', 's', 'Steps to redo',
            acceptsValue: true, default: '1');
    }

    protected function handle(): int
    {
        $steps = max(1, (int) $this->option('steps', '1'));
        $this->startProgress($steps * 2, "Redo {$steps} step(s)");
        $result = $this->service()->redo($steps);
        $this->finishProgress('Redo complete');

        if ($this->wantsJson()) {
            $this->emitJson((new JsonResultPresenter())->resultData($result));
            return self::SUCCESS;
        }

        $this->alertSuccess(
            "Redo complete ({$steps} step(s))",
            [
                "Rolled back: {$result->rolledBackCount()}",
                "Re-applied:  {$result->appliedCount()}",
            ],
        );

        return self::SUCCESS;
    }
}