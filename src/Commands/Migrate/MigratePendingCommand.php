<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;


use AlfaCode\LetMigrate\Support\JsonResultPresenter;
 
final class MigratePendingCommand extends LetMigrateCommand
{
    protected function configure(): void
    {
        $this->name        = 'migrate:pending';
        $this->description = 'List migrations that have not been applied yet';
 
        $this->registerCommonOptions();
    }
 
    protected function handle(): int
    {
        $pending = $this->service()->pending();
 
        if ($this->wantsJson()) {
            $this->emitJson((new JsonResultPresenter())->pendingData($pending));
            return self::SUCCESS;
        }
 
        if ($pending === []) {
            $this->success('All migrations are up to date.');
            return self::SUCCESS;
        }
 
        $this->section('Pending migrations (' . count($pending) . ')');
        foreach (array_keys($pending) as $name) {
            $this->info("  • {$name}");
        }
 
        return self::SUCCESS;
    }
}