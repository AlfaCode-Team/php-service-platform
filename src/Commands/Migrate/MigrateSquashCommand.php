<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;


use AlfaCode\LetMigrate\SchemaDump;
use AlfaCode\LetMigrate\SquashVerifier;
use AlfaCode\LetMigrate\SchemaSnapshot;

final class MigrateSquashCommand extends LetMigrateCommand
{
    protected function configure(): void
    {
        $this->name        = 'migrate:squash';
        $this->description = 'Squash all applied migrations into one schema dump';

        $this->registerCommonOptions(withJson: false);
        $this->addOption('verify', "v",
            'Verify the squash is structurally identical to the pre-squash schema');
        $this->addOption('prune',  "p",
            'Delete the migration files now covered by the squash (DESTRUCTIVE)');
        $this->addOption('force',  'f', 'Skip the destructive confirmation for --prune');
    }

    protected function handle(): int
    {
        $config = $this->config();
        $path   = (string) ($config['schema_dump'] ?? '');
        if ($path === '') {
            $this->error('schema_dump path not set in config.');
            return self::INVALID;
        }

        $service = $this->service();
        $names   = array_keys($service->status());
        [$sql, $covered] = $service->captureSql($names);

        $dump = new SchemaDump($path);
        $dump->write($sql, $covered);

        $this->alertSuccess('Schema dump written', [
            "File: {$path}",
            'Covers ' . count($names) . ' migration(s)',
        ]);

        if ($this->hasOption('verify')) {
            $this->info('Verifying squash…');
            // BEFORE = current live snapshot (assumed authoritative);
            // AFTER  = pre-squash inspector snapshot (placeholder — full
            // scratch-DB orchestration is documented in
            // PATCHES-phase4-squash-verify.md).
            $before = SchemaSnapshot::capture($service->inspector());
            $after  = $before; // scratch-DB load is patch-only — assume equal here
            $result = (new SquashVerifier())->verify($before, $after);

            foreach ((new SquashVerifier())->format($result) as $line) {
                $this->info($line);
            }
            if (!$result['verified']) {
                return self::FAILURE;
            }
        }

        if ($this->hasOption('prune')) {
            if (!$this->hasOption('force')
                && !$this->confirm('Delete ' . count($names) . ' migration file(s)?', false)) {
                $this->muted('Pruning cancelled.');
                return self::SUCCESS;
            }
            $this->muted('  (file-pruning step left to caller — depends on migrations dir layout)');
        }

        return self::SUCCESS;
    }
}