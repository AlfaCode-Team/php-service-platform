<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpIoCli\Components\TextInput;
use AlfacodeTeam\PhpIoCli\Depends\Shell;
use AlfacodeTeam\PhpIoCli\Depends\ShellResult;

/**
 * module:remove — fully remove a git submodule and clean up all traces.
 *
 * Usage:
 *   php cli module:remove <name>
 *
 * What it does:
 *   1. Shows a destruction manifest (Table)
 *   2. Asks for confirmation (Confirm)
 *   3. Asks the user to type the module name to double-confirm irreversible action
 *   4. git submodule deinit -f modules/<name>
 *   5. git rm -f modules/<name>
 *   6. rm -rf .git/modules/modules/<name>
 *   7. rm -rf modules/<name>
 *   8. Removes submodule section from .gitmodules
 *   9. Removes repository + require entries from root composer.json
 *  10. git commit -m "remove module <name>"
 */
final class ModuleRemoveCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'module:remove';
        $this->description = 'Completely remove a git submodule and its Composer registration';
        $this->help        = <<<'HELP'
Runs the full git submodule teardown sequence and removes all traces of
the module from .gitmodules and the root composer.json.

THIS IS DESTRUCTIVE AND IRREVERSIBLE. You will be asked to confirm twice.

Example:
  php cli module:remove payments
HELP;

        $this->addArgument('name', 'Module name to remove (kebab-case)', required: true);
        $this->addOption('yes', 'y', 'Skip interactive confirmation prompts (for CI)');
    }

    /* =========================================================
       HANDLE
    ========================================================= */

    protected function handle(): int
    {
        $name        = (string) $this->argument('name');
        $skipPrompt  = $this->hasOption('yes');
        $projectRoot = $this->projectRoot();
        $modulePath  = "modules/{$name}";

        /* ── 1. Guard ─────────────────────────────────────── */
        if (!$this->moduleExists($projectRoot, $modulePath)) {
            $this->alertWarning('Module not found', [
                "'{$modulePath}' is not registered in .gitmodules.",
                'Nothing to remove.',
            ]);
            return self::SUCCESS;
        }

        /* ── 2. Destruction manifest ──────────────────────── */
        $this->section('Module Removal Plan');

        $this->table()
            ->headers(['What', 'Target'])
            ->style('compact')
            ->rows([
                ['git submodule entry', $modulePath],
                ['git cache',           ".git/modules/{$modulePath}"],
                ['module directory',    $modulePath],
                ['.gitmodules section', "submodule.{$modulePath}"],
                ['root composer.json',  'repositories[] + require entry'],
            ])
            ->render();

        $this->alertWarning('This action is irreversible', [
            'All files in the module directory will be deleted.',
            'The git history of the submodule will remain in your remote.',
        ]);

        /* ── 3. Confirmations ─────────────────────────────── */
        if (!$skipPrompt) {
            if (!$this->confirm('Are you sure you want to remove this module?', default: false)) {
                $this->muted('Aborted.');
                return self::SUCCESS;
            }

            // FIX: embed the expected name directly in the TextInput question
            // so it renders as a single coherent component instead of orphaned
            // info()/muted() lines that bleed into the component's draw area.
            $typed = (new TextInput("Type  '{$name}'  to confirm deletion"))
                ->placeholder($name)
                ->validate(fn (string $v) => $v === $name
                    ? null
                    : "Expected '{$name}', got '{$v}'. Type it exactly.")
                ->run();

            if ((string) $typed !== $name) {
                $this->muted('Confirmation did not match. Aborted.');
                return self::SUCCESS;
            }
        }

        $this->newLine();

        /* ── 4. Single progress bar — one advance() per step ─
         *
         * FIX: only ONE ProgressBar instance draws at a time.
         * Previously each runStep() created its own indeterminate bar while
         * the outer determinate bar was also live — they tracked $lastLines
         * independently and stomped on each other, producing the interleaved
         * spinner frames seen in the output.
         *
         * Pattern: pass $overall to runStep() so it redraws the SAME bar on
         * every Shell tick (bounce physics are time-gated, rapid calls safe).
         * advance() is called once after the step completes, which moves the
         * determinate fill forward.
         * ──────────────────────────────────────────────────── */
        $overall = $this->progressBar('Removing module', 5);
        $overall->start();

        /* ── Step 1: git submodule deinit ─────────────────── */
        $result = $this->runStep(
            bar:     $overall,
            command: "git submodule deinit -f " . escapeshellarg($modulePath),
            cwd:     $projectRoot,
        );

        if ($result->failed()) {
            // Non-fatal — module may already be partially removed.
            $this->warning('git submodule deinit: ' . implode(' ', $result->meaningfulErrors()));
        }

        $overall->advance();

        /* ── Step 2: git rm ───────────────────────────────── */
        $result = $this->runStep(
            bar:     $overall,
            command: "git rm -f " . escapeshellarg($modulePath),
            cwd:     $projectRoot,
        );

        if ($result->failed()) {
            $this->warning('git rm: ' . implode(' ', $result->meaningfulErrors()));
        }

        $overall->advance();

        /* ── Step 3: remove .git/modules cache + working tree
         *
         * Pure PHP — no child process, so no Shell tick needed.
         * Just do the work; the bar redraws on the next advance().
         * ──────────────────────────────────────────────────── */
        $gitModulesCache = "{$projectRoot}/.git/modules/{$modulePath}";
        if (is_dir($gitModulesCache)) {
            $this->removeDirectory($gitModulesCache);
        }

        $absModulePath = "{$projectRoot}/{$modulePath}";
        if (is_dir($absModulePath)) {
            $this->removeDirectory($absModulePath);
        }

        $overall->advance();

        /* ── Step 4: clean .gitmodules + root composer.json ── */
        $err = $this->cleanGitmodules($projectRoot, $modulePath);
        if ($err !== null) {
            $this->warning("Could not clean .gitmodules: {$err}");
        }

        $err = $this->unpatchRootComposer($projectRoot, $modulePath, $name);
        if ($err !== null) {
            $this->warning("Could not clean root composer.json: {$err}");
        }

        $overall->advance();

        /* ── Step 5: git commit ───────────────────────────── */
        $result = $this->runStep(
            bar:     $overall,
            command: "git commit -m " . escapeshellarg("remove module {$name}"),
            cwd:     $projectRoot,
        );

        // Non-fatal: "nothing to commit" exits non-zero on some git versions.
        if ($result->failed()) {
            $this->warning('git commit: ' . implode(' ', $result->meaningfulErrors()));
        }

        $overall->advance();
        $overall->finish("Module '{$name}' removed");

        /* ── Done ─────────────────────────────────────────── */
        $this->alertSuccess("Module '{$name}' removed", [
            "All traces of '{$modulePath}' have been cleaned up.",
            'Run: git log --oneline to verify the commit.',
        ]);

        return self::SUCCESS;
    }

    /* =========================================================
       HELPERS
    ========================================================= */

    /**
     * Run a shell command while ticking an EXISTING ProgressBar.
     *
     * Accepts the live $bar by reference so that Shell::run()'s 50 ms tick
     * redraws the very same bar that advance() will later move forward.
     * No second ProgressBar is created — one bar owns the terminal at all times.
     */
    private function runStep(
        \AlfacodeTeam\PhpIoCli\Components\ProgressBar $bar,
        string $command,
        array  $env = [],
        string $cwd = '',
    ): ShellResult {
        return Shell::run(
            $command,
            tick: fn() => $bar->advance(0),  // redraw without incrementing current
            env:  $env,
            cwd:  $cwd,
        );
    }

    private function cleanGitmodules(string $projectRoot, string $modulePath): ?string
    {
        $file = "{$projectRoot}/.gitmodules";

        if (!is_file($file)) {
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return 'Cannot read .gitmodules';
        }

        $header  = preg_quote("[submodule \"{$modulePath}\"]", '/');
        $cleaned = preg_replace("/^{$header}\s*\n(?:(?!\[)[^\n]*\n)*/m", '', $content);

        if ($cleaned === null || $cleaned === $content) {
            return null;  // section not present — nothing to do
        }

        if (file_put_contents($file, $cleaned) === false) {
            return 'Cannot write .gitmodules';
        }

        Shell::run('git add ' . escapeshellarg($file), cwd: $projectRoot);
        return null;
    }

    private function unpatchRootComposer(string $projectRoot, string $modulePath, string $moduleName): ?string
    {
        $file = "{$projectRoot}/composer.json";

        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            return "Cannot read {$file}";
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($raw, associative: true);
        if (!is_array($data)) {
            return "Invalid JSON in {$file}";
        }

        file_put_contents("{$file}.bak", $raw);

        if (isset($data['repositories'])) {
            $data['repositories'] = array_values(array_filter(
                (array) $data['repositories'],
                fn ($r) => is_array($r) && ($r['url'] ?? '') !== $modulePath
            ));

            if (empty($data['repositories'])) {
                unset($data['repositories']);
            }
        }

        if (isset($data['require'])) {
            foreach (array_keys($data['require']) as $pkg) {
                if (str_ends_with((string) $pkg, "/{$moduleName}")) {
                    unset($data['require'][$pkg]);
                    break;
                }
            }
        }

        if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL) === false) {
            return "Cannot write {$file}";
        }

        return null;
    }

    private function moduleExists(string $projectRoot, string $modulePath): bool
    {
        $file = "{$projectRoot}/.gitmodules";
        return is_file($file) && str_contains((string) file_get_contents($file), "path = {$modulePath}");
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }

        rmdir($path);
    }
}