<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpIoCli\Components\ProgressBar;
use AlfacodeTeam\PhpIoCli\Depends\Shell;
use AlfacodeTeam\PhpIoCli\Depends\ShellResult;

/**
 * module:add — add a git submodule and wire it as a Composer path package.
 *
 * Usage:
 *   php cli module:add <name> <git-url> <org> [--offline]
 *
 * What it does (mirrors the bash script exactly, but with rich UI):
 *   1. Validates that the module does not already exist in .gitmodules
 *   2. git submodule add <url> modules/<name>
 *   3. git submodule update --init --recursive
 *   4. Scaffolds modules/<name>/src/ if missing
 *   5. Scaffolds modules/<name>/composer.json if missing (with PascalCase namespace)
 *   6. Patches root composer.json  (path repository + require entry)
 *   7. composer update  (or COMPOSER_DISABLE_NETWORK=1 when --offline)
 */
final class ModuleAddCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'module:add';
        $this->description = 'Add a git submodule and register it as a Composer path package';
        $this->help        = <<<'HELP'
Clones the module as a git submodule under modules/<name>, bootstraps its
src/ directory and composer.json if they are absent, then patches the root
composer.json so Composer can resolve the package via a path repository.

Example:
  php cli module:add payments git@github.com:acme/payments.git acme
  php cli module:add payments git@github.com:acme/payments.git acme --offline
HELP;

        $this->addArgument('name',    'Module name in kebab-case (e.g. user-auth)',  required: true);
        $this->addArgument('git-url', 'Git repository URL (SSH or HTTPS)',           required: true);
        $this->addArgument('org',     'Composer vendor / GitHub org (e.g. acme)',    required: true);
        $this->addOption('offline', 'o', 'Install without network (COMPOSER_DISABLE_NETWORK=1)');
    }

    /* =========================================================
       HANDLE
    ========================================================= */

    protected function handle(): int
    {
        $name    = (string) $this->argument('name');
        $gitUrl  = (string) $this->argument('git-url');
        $org     = (string) $this->argument('org');
        $offline = $this->hasOption('offline');

        $projectRoot   = $this->projectRoot();
        $moduleRelPath = "modules/{$name}";
        $modulePath    = "{$projectRoot}/{$moduleRelPath}";
        $packageName   = "{$org}/{$name}";
        $nsOrg         = $this->toPascalCase($org);
        $nsMod         = $this->toPascalCase($name);

        /* ── 1. Summary table ─────────────────────────────── */
        $this->section('Module Add Plan');

        $this->table()
            ->headers(['Field', 'Value'])
            ->style('compact')
            ->rows([
                ['Module name',   $name],
                ['Git URL',       $gitUrl],
                ['Path',          $moduleRelPath],
                ['Package',       $packageName],
                ['Namespace',     "{$nsOrg}\\{$nsMod}\\"],
                ['Composer mode', $offline ? 'offline (no network)' : 'online'],
            ])
            ->render();

        /* ── 2. Confirm ───────────────────────────────────── */
        if (!$this->confirm('Proceed with adding this module?')) {
            $this->muted('Aborted.');
            return self::SUCCESS;
        }

        $this->newLine();

        /* ── 3. Guard: already registered? ───────────────── */
        if ($this->moduleExists($projectRoot, $moduleRelPath)) {
            $this->alertWarning(
                'Module already exists',
                ["'{$moduleRelPath}' is already registered in .gitmodules. Nothing to do."]
            );
            return self::SUCCESS;
        }

        /* ── 4. Single determinate bar — one advance() per completed step ──
         *
         * Only ONE ProgressBar instance is ever live at a time.
         * Shell steps pass $overall into runStep() so the tick callback
         * redraws the same bar (advance(0) = redraw only, no increment).
         * Pure-PHP steps do their work silently; the bar redraws on the
         * next advance() call which moves the fill forward.
         * ──────────────────────────────────────────────────────────────── */
        $overall = $this->progressBar('Module bootstrap', 6);
        $overall->start();

        /* ── Step 1: git submodule add ────────────────────── */
        $result = $this->runStep(
            bar:     $overall,
            command: "git submodule add " . escapeshellarg($gitUrl) . " " . escapeshellarg($moduleRelPath),
            cwd:     $projectRoot,
        );

        if ($result->failed()) {
            $overall->finish('Aborted');
            $this->alertError('git submodule add failed', $result->meaningfulErrors());
            return self::FAILURE;
        }

        $overall->advance();

        /* ── Step 2: git submodule update ────────────────── */
        $result = $this->runStep(
            bar:     $overall,
            command: 'git submodule update --init --recursive',
            cwd:     $projectRoot,
        );

        if ($result->failed()) {
            $overall->finish('Aborted');
            $this->alertError('Submodule initialisation failed', $result->meaningfulErrors());
            return self::FAILURE;
        }

        $overall->advance();

        /* ── Step 3: scaffold src/ (pure PHP — no child process) ── */
        $srcPath = "{$modulePath}/src";
        if (!is_dir($srcPath) && !mkdir($srcPath, 0755, true)) {
            $overall->finish('Aborted');
            $this->alertError("Could not create {$srcPath}", []);
            return self::FAILURE;
        }

        $overall->advance();

        /* ── Step 4: scaffold module composer.json ────────── */
        $moduleComposer = "{$modulePath}/composer.json";

        if (!is_file($moduleComposer)) {
            $composerData = [
                'name'        => $packageName,
                'description' => 'Auto-generated module',
                'type'        => 'library',
                'autoload'    => ['psr-4' => ["{$nsOrg}\\{$nsMod}\\" => 'src/']],
                'require'           => ['php' => '^8.2'],
                'minimum-stability' => 'dev',
                'prefer-stable'     => true,
            ];

            $written = file_put_contents(
                $moduleComposer,
                json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
            );

            if ($written === false) {
                $overall->finish('Aborted');
                $this->alertError("Could not write {$moduleComposer}", []);
                return self::FAILURE;
            }
        }

        $overall->advance();

        /* ── Step 5: patch root composer.json ────────────── */
        $patchErr = $this->patchRootComposer($projectRoot, $moduleRelPath, $packageName);

        if ($patchErr !== null) {
            $overall->finish('Aborted');
            $this->alertError('Failed to patch root composer.json', [$patchErr]);
            return self::FAILURE;
        }

        $overall->advance();

        /* ── Step 6: composer update ──────────────────────── */
        $composerEnv = $offline ? ['COMPOSER_DISABLE_NETWORK' => '1'] : [];
        $composerCmd = $offline ? 'composer update --no-dev' : 'composer update';

        $result = $this->runStep(
            bar:     $overall,
            command: $composerCmd,
            env:     $composerEnv,
            cwd:     $projectRoot,
        );

        if ($result->failed()) {
            $overall->finish('Aborted');
            $this->alertError('composer update failed', $result->meaningfulErrors());
            return self::FAILURE;
        }

        $overall->advance();
        $overall->finish('All steps complete');

        /* ── Done ─────────────────────────────────────────── */
        $this->alertSuccess("Module '{$name}' added successfully", [
            "Path:      {$modulePath}",
            "Package:   {$packageName}",
            "Namespace: {$nsOrg}\\{$nsMod}\\",
        ]);

        return self::SUCCESS;
    }

    /* =========================================================
       HELPERS
    ========================================================= */

    /**
     * Run a shell command while keeping the existing ProgressBar animated.
     *
     * Shell::run() fires $tick on every ≤50 ms poll cycle. We call
     * $bar->advance(0) which redraws the bar without moving $current forward —
     * the bounce animation time-gates itself inside getBounceVisual() so rapid
     * calls are harmless. No second ProgressBar is ever created.
     */
    private function runStep(
        ProgressBar $bar,
        string      $command,
        array       $env = [],
        string      $cwd = '',
    ): ShellResult {
        return Shell::run(
            $command,
            tick: fn() => $bar->advance(0),  // redraw only — does not increment $current
            env:  $env,
            cwd:  $cwd,
        );
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function moduleExists(string $projectRoot, string $moduleRelPath): bool
    {
        $file = "{$projectRoot}/.gitmodules";
        return is_file($file) && str_contains((string) file_get_contents($file), "path = {$moduleRelPath}");
    }

    /**
     * @return string|null null on success, error message on failure
     */
    private function patchRootComposer(string $projectRoot, string $moduleRelPath, string $packageName): ?string
    {
        $file = "{$projectRoot}/composer.json";

        if (!is_file($file)) {
            return "File not found: {$file}";
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

        $repos = array_values(array_filter(
            (array) ($data['repositories'] ?? []),
            fn ($r) => is_array($r) && ($r['url'] ?? '') !== $moduleRelPath
        ));
        $repos[]              = ['type' => 'path', 'url' => $moduleRelPath];
        $data['repositories'] = $repos;

        $data['require'] ??= [];
        $data['require'][$packageName] = '*';

        if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL) === false) {
            return "Cannot write {$file}";
        }

        return null;
    }

    private function toPascalCase(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }
}