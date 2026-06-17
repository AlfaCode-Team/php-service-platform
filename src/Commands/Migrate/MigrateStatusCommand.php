<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;

use AlfaCode\LetMigrate\Support\StatusRenderer;
use AlfacodeTeam\PhpIoCli\Depends\Colors;
 
final class MigrateStatusCommand extends LetMigrateCommand
{
    protected function configure(): void
    {
        $this->name        = 'migrate:status';
        $this->description = 'Show the status of every known migration';
 
        $this->registerCommonOptions();
    }
 
    protected function handle(): int
    {
        $status = $this->service()->status();
 
        if ($this->wantsJson()) {
            $this->emitJson((new StatusRenderer())->toData($status));
            return self::SUCCESS;
        }
 
        if ($status === []) {
            $this->info('No migrations registered.');
            return self::SUCCESS;
        }
 
        $this->section('Migration status');
 
        $rows = [];
        foreach ($status as $name => $info) {
            $state   = (string) ($info['status'] ?? 'unknown');
            $applied = (string) ($info['applied_at'] ?? '');
            $rows[]  = [
                $name,
                match ($state) {
                    'applied' => Colors::wrap('● applied', Colors::GREEN),
                    'pending' => Colors::wrap('○ pending', Colors::YELLOW),
                    default   => Colors::muted("? {$state}"),
                },
                $applied !== '' ? $applied : Colors::muted('—'),
            ];
        }
 
        $this->table()
            ->headers(['Migration', 'Status', 'Applied at'])
            ->rows($rows)
            ->render();
 
        $applied = count(array_filter($status,
            static fn($r) => ($r['status'] ?? '') === 'applied'));
        $pending = count($status) - $applied;
        $this->muted("  {$applied} applied · {$pending} pending · " . count($status) . ' total');
 
        return self::SUCCESS;
    }
}
 