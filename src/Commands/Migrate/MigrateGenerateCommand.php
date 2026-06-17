<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;

use AlfaCode\LetMigrate\MigrationGenerator;
use AlfaCode\LetMigrate\SchemaSnapshot;
 
/**
 * migrate:generate — reverse-engineer the live database into migration files.
 *
 * Uses the verified API:
 *   new MigrationGenerator(SchemaInspectorInterface $inspector)
 *       ->generate(): array<string, string>   // filename => PHP source
 *
 * Tables are emitted in foreign-key dependency order (Kahn topological sort)
 * by the generator itself; this command only handles file I/O.
 */
final class MigrateGenerateCommand extends LetMigrateCommand
{
    protected function configure(): void
    {
        $this->name        = 'migrate:generate';
        $this->description = 'Reverse-engineer the current database into migration files';
 
        $this->registerCommonOptions(withJson: false);
        $this->addOption('output-dir', 'o',
            'Directory to write generated migrations into (defaults to first configured paths[])',
            acceptsValue: true);
        $this->addOption('stdout', 's',
            'Print all migrations to stdout instead of writing files');
        $this->addOption('force', 'f',
            'Overwrite existing migration files');
    }
 
    protected function handle(): int
    {
        $generator  = new MigrationGenerator($this->inspector());
        $migrations = $generator->generate();   // [filename => source]
 
        if ($migrations === []) {
            $this->info('No tables found — nothing to generate.');
            return self::SUCCESS;
        }
 
        // ── --stdout: concatenate and print, never write ───────────────
        if ($this->hasOption('stdout')) {
            foreach ($migrations as $filename => $source) {
                echo "// ───── {$filename} ─────\n";
                echo $source;
                echo "\n\n";
            }
            return self::SUCCESS;
        }
 
        // ── Resolve output directory ───────────────────────────────────
        $outputDir = (string) ($this->option('output-dir') ?? '');
        if ($outputDir === '') {
            $paths     = (array) ($this->config()['paths'] ?? []);
            $outputDir = (string) ($paths[0] ?? '');
        }
        if ($outputDir === '') {
            $this->error('No output directory: pass --output-dir=… or set paths[] in config.');
            return self::FAILURE;
        }
        if (!is_dir($outputDir) && !mkdir($outputDir, 0o755, true) && !is_dir($outputDir)) {
            $this->error("Could not create directory: {$outputDir}");
            return self::FAILURE;
        }
 
        // ── Write files; refuse to overwrite without --force ───────────
        $written = [];
        foreach ($migrations as $filename => $source) {
            $target = rtrim($outputDir, '/') . '/' . $filename;
            if (file_exists($target) && !$this->hasOption('force')) {
                $this->muted("  Skipped (already exists): {$filename}");
                continue;
            }
            if (file_put_contents($target, $source) === false) {
                $this->error("Could not write {$target}");
                return self::FAILURE;
            }
            $written[] = $filename;
        }
 
        $this->alertSuccess('Migrations generated', [
            'Output:  ' . $outputDir,
            'Files:   ' . count($written),
            'Skipped: ' . (count($migrations) - count($written)),
        ]);
        return self::SUCCESS;
    }
}