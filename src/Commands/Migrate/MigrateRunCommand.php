<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;


use AlfaCode\LetMigrate\Support\JsonResultPresenter;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
use AlfaCode\LetMigrate\Event\MigrationFailed;
use AlfaCode\LetMigrate\Event\MigrationFinished;
use AlfaCode\LetMigrate\Event\MigrationStarted;
use AlfaCode\LetMigrate\DeployLock;
use AlfaCode\LetMigrate\MigrationLinter;
use AlfacodeTeam\PhpIoCli\Components\ProgressBar;
 
final class MigrateRunCommand extends LetMigrateCommand
{
    /** Live progress bar — set up in handle(), driven by events. */
    private ?ProgressBar $bar = null;
 
    /** Names of migrations that succeeded; used in error rendering. */
    private array $succeeded = [];
 
    protected function configure(): void
    {
        $this->name        = 'migrate:run';
        $this->description = 'Run all pending migrations';
 
        $this->registerCommonOptions();
        $this->addOption('pretend', 'p',
            'Capture SQL instead of executing it');
        $this->addOption('force',   'f',
            'Bypass destructive-operation lint guard');
        $this->addOption('lock',    "l",
            'Hold an advisory deploy lock so concurrent runs cannot race');
        $this->addOption('lock-timeout', "lt",
            'Seconds to wait for the lock', acceptsValue: true, default: '10');
    }
 
    /**
     * Live feedback wiring:
     *   • MigrationStarted   → status line above the bar
     *   • MigrationFinished  → bar.advance(1, name)
     *   • MigrationFailed    → red error line; bar is finalised in handle()
     *
     * Skipped under --json (the JSON consumer wants a clean payload).
     */
    protected function configureEvents(MigrationEventDispatcher $events): void
    {
        if ($this->wantsJson()) {
            return;
        }
 
        $events->on(MigrationStarted::class,
            function (MigrationStarted $e): void {
                if ($this->bar !== null) {
                    // Advance(0) just refreshes the label without moving forward.
                    $this->bar->advance(0, "{$e->direction}: {$e->migration}");
                }
            });
 
        $events->on(MigrationFinished::class,
            function (MigrationFinished $e): void {
                $this->succeeded[] = $e->migration;
                if ($this->bar !== null) {
                    $this->bar->advance(1, "✓ {$e->migration}");
                }
            });
 
        $events->on(MigrationFailed::class,
            function (MigrationFailed $e): void {
                if ($this->bar !== null) {
                    $this->bar->finish('Migration failed');
                    $this->bar = null;
                }
                $this->error("✘ {$e->migration}: " . $e->exception->getMessage());
            });
    }
 
    protected function handle(): int
    {

        $service = $this->service();
        $pending = $service->pending();
 
        if ($pending === []) {
            $this->info('Nothing to migrate.');
            return $this->wantsJson() ? $this->emitEmptyJson() : self::SUCCESS;
        }


 
        // ── --pretend: capture SQL, don't execute (no events fire) ─────
        if ($this->hasOption('pretend')) {
            [$sqlStatements] = $service->captureSql(array_keys($pending));
            foreach ($sqlStatements as $sql) {
                echo $sql . ";\n";
            }
            return self::SUCCESS;
        }

        // ── lint guard (blocks destructive ops without --force) ────────
        if (!$this->hasOption('force')) {
            [$statements] = $service->captureSql(array_keys($pending));
            $linter     = new MigrationLinter();
            if ($linter->hasDanger($statements)) {
                $this->alertError('Destructive migration blocked', array_merge(
                    $linter->format($linter->lint($statements)),
                    ['', 'Re-run with --force to override.'],
                ));
                return self::FAILURE;
            }
        }
 
        // ── Start the live progress bar (only on the human path) ───────
        if (!$this->wantsJson()) {
            $this->bar = $this->progressBar('Running migrations', count($pending));
            $this->bar->start();
        }
 
        $run = fn() => $service->run();
 
        try {
            $result = $this->hasOption('lock')
                ? (new DeployLock(
                    $service->driver(),
                    ($this->config()['prefix'] ?? '') . 'let_migrate_deploy',
                ))->withLock($run, (int) $this->option('lock-timeout', '10'))
                : $run();
        } catch (\Throwable $e) {
            if ($this->bar !== null) {
                $this->bar->finish('Aborted');
                $this->bar = null;
            }
            $this->error($e->getMessage());
            return self::FAILURE;
        }
 
        if ($this->bar !== null) {
            $this->bar->finish('All migrations applied');
            $this->bar = null;
        }
 
        if ($this->wantsJson()) {
            $this->emitJson((new JsonResultPresenter())->resultData($result));
            return self::SUCCESS;
        }
 
        $this->alertSuccess('Migrations applied', [
            "Count: {$result->appliedCount()}",
            "Batch: {$result->batch}",
        ]);
        return self::SUCCESS;
    }
 
    private function emitEmptyJson(): int
    {
        $this->emitJson([
            'applied' => [], 'rolled_back' => [], 'batch' => 0,
            'applied_count' => 0, 'rolled_back_count' => 0,
            'empty' => true, 'summary' => 'Nothing to migrate.',
        ]);
        return self::SUCCESS;
    }
}