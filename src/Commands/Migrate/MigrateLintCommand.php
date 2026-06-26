<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;

use AlfaCode\LetMigrate\MigrationLinter;

final class MigrateLintCommand extends LetMigrateCommand
{
    protected function configure(): void
    {
        $this->name        = 'migrate:lint';
        $this->description = 'Statically check pending migrations for destructive operations';

        $this->registerCommonOptions();
    }

    protected function handle(): int
    {
        $pending    = $this->service()->pending();
        $statements = [];
        if ($pending !== []) {
            [$statements] = $this->service()->captureSql(array_keys($pending));
        }

        $linter   = new MigrationLinter();
        $findings = $linter->lint($statements);
        $danger   = $linter->hasDanger($statements);

        if ($this->wantsJson()) {
            $this->emitJson([
                'findings' => $findings,
                'danger'   => $danger,
            ]);
            return $danger ? self::FAILURE : self::SUCCESS;
        }

        if ($findings === []) {
            $this->success('✓ No destructive operations detected.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($findings as $f) {
            $rows[] = [
                mb_strtoupper($f['severity']),
                $f['message'],
                $f['sql'],
            ];
        }
        $this->table()
            ->headers(['Severity', 'Issue', 'SQL'])
            ->rows($rows)
            ->render();

        if ($danger) {
            $this->alertError('Destructive migrations found',
                ['Re-run migrate:run with --force to override.']);
            return self::FAILURE;
        }

        $this->alertWarning('Warnings only — safe to proceed.');
        return self::SUCCESS;
    }
}