<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpIoCli\Components\SpinnerComponent;
use AlfacodeTeam\PhpIoCli\Components\TextInput;
use AlfacodeTeam\PhpIoCli\Depends\Colors;
use AlfacodeTeam\PhpIoCli\Depends\Shell;
use AlfacodeTeam\PhpIoCli\Depends\ShellResult;

/**
 * module:remove — fully remove a git submodule and clean up all traces.
 *
 * Usage:
 *   php cli module:remove <name>
 *
 * What it does (mirrors the bash script exactly, but with rich UI and safety gates):
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
        $this->addOption('--yes', '-y', 'Skip interactive confirmation prompts (for CI)');
    }

    /* =========================================================
       HANDLE
    ========================================================= */

    protected function handle(): int
    {
        $name       = (string) $this->argument('name');
        $skipPrompt = $this->hasOption('yes');
        $modulePath = "modules/{$name}";

        /* ── 1. Guard: does the module exist at all? ──────── */
        if (!$this->moduleExists($modulePath)) {
            $this->alertWarning(
                'Module not found',
                [
                    "'{$modulePath}' is not registered in .gitmodules.",
                    'Nothing to remove.',
                ]
            );
            return self::SUCCESS;
        }

        /* ── 2. Destruction manifest ──────────────────────── */
        $this->section('Module Removal Plan');

        $willRemove = [
            ['git submodule entry',    $modulePath],
            ['git cache',              ".git/modules/{$modulePath}"],
            ['module directory',       $modulePath],
            ['.gitmodules section',    "submodule.{$modulePath}"],
            ['root composer.json',     'repositories[] + require entry'],
        ];

        $this->table()
            ->headers(['What', 'Target'])
            ->style('compact')
            ->rows($willRemove)
            ->render();

        $this->alertWarning(
            'This action is irreversible',
            [
                'All files in the module directory will be deleted.',
                'The git history of the submodule will remain in your remote.',
            ]
        );

        /* ── 3. First confirmation ────────────────────────── */
        if (!$skipPrompt) {
            if (!$this->confirm('Are you sure you want to remove this module?', default: false)) {
                $this->muted('Aborted.');
                return self::SUCCESS;
            }

            /* ── 4. Second gate: type the module name ─────── */
            $this->newLine();
            $this->line(
                Colors::wrap('  Type the module name to confirm: ', Colors::BOLD)
                . Colors::wrap($name, Colors::RED)
            );
            $this->newLine();

            $typed = (new TextInput('Confirm module name'))
                ->placeholder($name)
                ->validate(function (string $v) use ($name): ?string {
                    return $v === $name
                        ? null
                        : "You typed '{$v}', expected '{$name}'. Type it exactly to confirm.";
                })
                ->run();

            if ((string) $typed !== $name) {
                $this->muted('Confirmation did not match. Aborted.');
                return self::SUCCESS;
            }
        }

        $this->newLine();

        /* ── 5. Overall progress bar (5 steps) ───────────── */
        $progress = $this->progressBar('Removing module', 5);
        $progress->start();

        /* ── Step 1: git submodule deinit ─────────────────── */
        $result = $this->runWithSpinner(
            label:   'git submodule deinit',
            command: "git submodule deinit -f " . escapeshellarg($modulePath),
        );

        // deinit failing is non-fatal (module might already be partially removed)
        if ($result->failed()) {
            $this->warning(
                'git submodule deinit reported errors (continuing): '
                . implode(' ', $result->meaningfulErrors())
            );
        } else {
            // Only print the stop message when the spinner was properly started
        }

        $progress->advance();

        /* ── Step 2: git rm ───────────────────────────────── */
        $result = $this->runWithSpinner(
            label:   'git rm',
            command: "git rm -f " . escapeshellarg($modulePath),
        );

        if ($result->failed()) {
            $this->warning(
                'git rm reported errors (continuing): '
                . implode(' ', $result->meaningfulErrors())
            );
        }

        $progress->advance();

        /* ── Step 3: remove .git/modules cache ────────────── */
        $spin = $this->spinner('Removing .git/modules cache');
        $spin->start();
        usleep(60_000);

        $gitModulesCache = ".git/modules/{$modulePath}";
        if (is_dir($gitModulesCache)) {
            $this->removeDirectory($gitModulesCache);
        }

        // Also remove any leftover working-tree directory
        if (is_dir($modulePath)) {
            $this->removeDirectory($modulePath);
        }

        $spin->stop('Cache and working-tree directory removed');
        $progress->advance();

        /* ── Step 4: clean .gitmodules ────────────────────── */
        $patchErr = $this->cleanGitmodules($modulePath);
        if ($patchErr !== null) {
            $this->warning("Could not clean .gitmodules: {$patchErr}");
        }

        $patchErr = $this->unpatchRootComposer($modulePath, $name);
        if ($patchErr !== null) {
            $this->warning("Could not clean root composer.json: {$patchErr}");
        }

        $progress->advance();

        /* ── Step 5: git commit ───────────────────────────── */
        $result = $this->runWithSpinner(
            label:   'git commit',
            command: "git commit -m " . escapeshellarg("remove module {$name}"),
        );

        if ($result->failed()) {
            $this->warning(
                'git commit failed (you may need to commit manually): '
                . implode(' ', $result->meaningfulErrors())
            );
        }

        $progress->advance();
        $progress->finish('Removal complete');

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

    private function runWithSpinner(
        string $label,
        string $command,
        array  $env   = [],
        string $style = 'dots',
    ): ShellResult {
        $spin = new SpinnerComponent($label, $style);
        $spin->start();

        $result = Shell::run(
            $command,
            tick: function (string $lastLine, bool $isStderr) use ($spin): void {
                $spin->tick($lastLine !== '' ? Colors::muted($lastLine) : '');
            },
            env: $env,
        );

        if ($result->ok()) {
            $spin->stop($label);
        } else {
            $spin->fail($label . ' (errors reported)');
        }

        return $result;
    }

    /**
     * Removes a [submodule "modules/<name>"] section from .gitmodules.
     * Pure PHP replacement for:
     *   git config -f .gitmodules --remove-section "submodule.$MODULE_PATH"
     *
     * @return string|null null on success, error message on failure
     */
    private function cleanGitmodules(string $modulePath): ?string
    {
        $spin = $this->spinner('Cleaning .gitmodules');
        $spin->start();
        usleep(60_000);

        if (!is_file('.gitmodules')) {
            $spin->stop('.gitmodules not found — nothing to clean');
            return null;
        }

        $content = file_get_contents('.gitmodules');
        if ($content === false) {
            $spin->fail('Cannot read .gitmodules');
            return 'Cannot read .gitmodules';
        }

        // Remove the INI section block for this submodule.
        // Pattern matches: [submodule "modules/name"] + all key=value lines until the next [section] or EOF
        $sectionHeader = preg_quote("[submodule \"{$modulePath}\"]", '/');
        $cleaned = preg_replace(
            "/^{$sectionHeader}\s*\n(?:(?!\[)[^\n]*\n)*/m",
            '',
            $content
        );

        if ($cleaned === null || $cleaned === $content) {
            // Section was not found — not necessarily an error
            $spin->stop('.gitmodules: section not found, skipped');
            return null;
        }

        $written = file_put_contents('.gitmodules', $cleaned);
        if ($written === false) {
            $spin->fail('Cannot write .gitmodules');
            return 'Cannot write .gitmodules';
        }

        // Stage the change
        Shell::run('git add .gitmodules');
        $spin->stop('.gitmodules cleaned');
        return null;
    }

    /**
     * Removes the path repository entry and require key for this module
     * from the root composer.json.
     *
     * @return string|null null on success, error message on failure
     */
    private function unpatchRootComposer(string $modulePath, string $moduleName): ?string
    {
        $spin = $this->spinner('Cleaning root composer.json');
        $spin->start();
        usleep(60_000);

        $composerFile = 'composer.json';

        if (!is_file($composerFile)) {
            $spin->stop('root composer.json not found — skipped');
            return null;
        }

        $raw = file_get_contents($composerFile);
        if ($raw === false) {
            $spin->fail('Cannot read composer.json');
            return "Cannot read {$composerFile}";
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($raw, associative: true);
        if (!is_array($data)) {
            $spin->fail('Invalid JSON in composer.json');
            return "Invalid JSON in {$composerFile}";
        }

        // Backup
        file_put_contents("{$composerFile}.bak", $raw);

        // Remove path repository
        if (isset($data['repositories'])) {
            $data['repositories'] = array_values(array_filter(
                (array) $data['repositories'],
                fn ($r) => is_array($r) && ($r['url'] ?? '') !== $modulePath
            ));

            // Clean up empty repositories array
            if (empty($data['repositories'])) {
                unset($data['repositories']);
            }
        }

        // Remove require entry — try both "org/name" patterns across any org
        if (isset($data['require'])) {
            foreach (array_keys($data['require']) as $pkg) {
                // Match any package whose name segment matches the module name
                if (str_ends_with((string) $pkg, "/{$moduleName}")) {
                    unset($data['require'][$pkg]);
                    break;
                }
            }
        }

        $written = file_put_contents(
            $composerFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );

        if ($written === false) {
            $spin->fail('Cannot write composer.json');
            return "Cannot write {$composerFile}";
        }

        $spin->stop('root composer.json cleaned');
        return null;
    }

    /**
     * Check whether $modulePath is registered in .gitmodules.
     */
    private function moduleExists(string $modulePath): bool
    {
        if (!is_file('.gitmodules')) {
            return false;
        }

        return str_contains(
            (string) file_get_contents('.gitmodules'),
            "path = {$modulePath}"
        );
    }

    /**
     * Recursively delete a directory (PHP equivalent of rm -rf).
     * Works on Linux, macOS, and Windows.
     */
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
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($path);
    }
}