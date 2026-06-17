<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;

use AlfaCode\LetMigrate\SchemaDiffer;
use AlfaCode\LetMigrate\SchemaSnapshot;

final class MigrateCheckCommand extends LetMigrateCommand
{
    protected function configure(): void
    {
        $this->name        = 'migrate:check';
        $this->description = 'CI drift guard — exits non-zero if the live schema differs from the target';

        $this->registerCommonOptions();
    }

    protected function handle(): int
    {
        // FROM = live DB, TO = the schema the migrations produce on a clean DB.
        $current = SchemaSnapshot::capture($this->inspector());
        $target  = $this->targetSnapshot();
        $diff    = (new SchemaDiffer())->diff($current, $target, true);

        if ($this->wantsJson()) {
            $this->emitJson(['drift' => !$diff['empty'], 'diff' => $diff]);
            return $diff['empty'] ? self::SUCCESS : self::FAILURE;
        }

        if ($diff['empty']) {
            $this->success('No schema drift.');
            return self::SUCCESS;
        }

        $this->alertError('Schema drift detected', [
            'Run migrate:diff to produce a reconciliation migration.',
        ]);
        return self::FAILURE;
    }
}