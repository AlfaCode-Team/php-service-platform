<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;

use AlfaCode\LetMigrate\SchemaDiffer;
use AlfaCode\LetMigrate\SchemaSnapshot;

/**
 * migrate:diff — diff the live database against the migration-defined target
 * state and emit a single reconciliation ("delta") migration.
 *
 * FROM = the live database (current schema, via the schema inspector).
 * TO   = the target schema the project's migrations produce (built by applying
 *        every migration to a throwaway SQLite database — see
 *        LetMigrateCommand::targetSnapshot()).
 *
 * In save-diff mode (the default) destructive operations (DROP TABLE / DROP
 * COLUMN) are omitted; pass --force to include them.
 */
final class MigrateDiffCommand extends LetMigrateCommand
{
    protected function configure(): void
    {
        $this->name        = 'migrate:diff';
        $this->description = 'Diff the live database against migration-defined state and emit a delta migration';

        $this->registerCommonOptions(withJson: false);
        $this->addOption('output-dir', 'o',
            'Directory to write the delta migration (defaults to first paths[])',
            acceptsValue: true);
        $this->addOption('stdout', '',
            'Print the delta to stdout instead of writing a file');
        $this->addOption('force', 'f',
            'Include destructive DROPs (default: save-diff mode omits them)');
    }

    protected function handle(): int
    {
        $paths     = (array) ($this->config()['paths'] ?? []);
        $targetDir = (string) ($paths[0] ?? '');
        if ($targetDir === '') {
            $this->error('No migrations directory configured (paths[] is empty).');
            return self::FAILURE;
        }

        $allowDestructive = $this->hasOption('force');

        // FROM = live DB, TO = migrations-applied target.
        $from = SchemaSnapshot::capture($this->inspector());
        $to   = $this->targetSnapshot();

        $differ = new SchemaDiffer();
        $diff   = $differ->diff($from, $to, $allowDestructive);

        if ($diff['empty']) {
            $this->info('Schemas match — no diff migration needed.');
            return self::SUCCESS;
        }

        $filename = date('Y_m_d_His') . '_schema_diff.php';
        $source   = $differ->render($diff, $to, $filename);

        if ($this->hasOption('stdout')) {
            echo "// ───── {$filename} ─────\n";
            echo $source;
            echo "\n";
            return self::SUCCESS;
        }

        $outputDir = (string) ($this->option('output-dir') ?? $targetDir);
        if (!is_dir($outputDir) && !mkdir($outputDir, 0o755, true) && !is_dir($outputDir)) {
            $this->error("Could not create directory: {$outputDir}");
            return self::FAILURE;
        }

        $target = rtrim($outputDir, '/') . '/' . $filename;
        if (file_put_contents($target, $source) === false) {
            $this->error("Could not write {$target}");
            return self::FAILURE;
        }

        $this->alertSuccess('Delta migration generated', [
            'File:   ' . $filename,
            'Output: ' . $outputDir,
            'Mode:   ' . ($allowDestructive ? 'destructive (DROPs included)' : 'save-diff'),
        ]);
        return self::SUCCESS;
    }
}
