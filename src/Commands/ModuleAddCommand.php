<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpIoCli\Components\SpinnerComponent;
use AlfacodeTeam\PhpIoCli\Depends\Colors;
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

        $this->addArgument('name',    'Module name in kebab-case (e.g. user-auth)',       required: true);
        $this->addArgument('git-url', 'Git repository URL (SSH or HTTPS)',                required: true);
        $this->addArgument('org',     'Composer vendor / GitHub org (e.g. acme)',         required: true);
        $this->addOption('--offline', '-o', 'Install without network (COMPOSER_DISABLE_NETWORK=1)');
    }

    /* =========================================================
       HANDLE
    ========================================================= */

    protected function handle(): int
    {
        $name       = (string) $this->argument('name');
        $gitUrl     = (string) $this->argument('git-url');
        $org        = (string) $this->argument('org');
        $offline    = $this->hasOption('offline');

        $modulePath  = "modules/{$name}";
        $packageName = "{$org}/{$name}";
        $nsOrg       = $this->toPascalCase($org);
        $nsMod       = $this->toPascalCase($name);

        /* ── 1. Summary table ─────────────────────────────── */
        $this->section('Module Add Plan');

        $this->table()
            ->headers(['Field', 'Value'])
            ->style('compact')
            ->rows([
                ['Module name',     $name],
                ['Git URL',         $gitUrl],
                ['Path',            $modulePath],
                ['Package',         $packageName],
                ['Namespace',       "{$nsOrg}\\{$nsMod}\\"],
                ['Composer mode',   $offline ? 'offline (no network)' : 'online'],
            ])
            ->render();

        /* ── 2. Confirm ───────────────────────────────────── */
        if (!$this->confirm('Proceed with adding this module?')) {
            $this->muted('Aborted.');
            return self::SUCCESS;
        }

        $this->newLine();

        /* ── 3. Guard: already registered? ───────────────── */
        if ($this->moduleExists($modulePath)) {
            $this->alertWarning(
                'Module already exists',
                ["'{$modulePath}' is already registered in .gitmodules. Nothing to do."]
            );
            return self::SUCCESS;
        }

        /* ── 4. Overall progress bar (6 steps) ───────────── */
        $progress = $this->progressBar('Module bootstrap', 6);
        $progress->start();

        /* ── Step 1: git submodule add ────────────────────── */
        $result = $this->runWithSpinner(
            label:   'git submodule add',
            command: "git submodule add " . escapeshellarg($gitUrl) . " " . escapeshellarg($modulePath),
        );

        if ($result->failed()) {
            $progress->finish('Aborted');
            $this->alertError(
                'git submodule add failed',
                $result->meaningfulErrors()
            );
            return self::FAILURE;
        }

        $progress->advance();

        /* ── Step 2: git submodule update ────────────────── */
        $result = $this->runWithSpinner(
            label:   'git submodule update --init --recursive',
            command: 'git submodule update --init --recursive',
        );

        if ($result->failed()) {
            $progress->finish('Aborted');
            $this->alertError(
                'Submodule initialisation failed',
                $result->meaningfulErrors()
            );
            return self::FAILURE;
        }

        $progress->advance();

        /* ── Step 3: scaffold src/ ────────────────────────── */
        $srcPath = "{$modulePath}/src";

        $spin = $this->spinner('Checking module structure');
        $spin->start();
        usleep(80_000);

        if (!is_dir($srcPath)) {
            if (!mkdir($srcPath, 0755, true)) {
                $spin->fail("Could not create {$srcPath}");
                $progress->finish('Aborted');
                return self::FAILURE;
            }
        }

        $spin->stop("src/ directory ready");
        $progress->advance();

        /* ── Step 4: scaffold module composer.json ────────── */
        $moduleComposer = "{$modulePath}/composer.json";

        $spin = $this->spinner('Scaffolding module composer.json');
        $spin->start();
        usleep(60_000);

        if (!is_file($moduleComposer)) {
            $composerData = [
                'name'        => $packageName,
                'description' => 'Auto-generated module',
                'type'        => 'library',
                'autoload'    => [
                    'psr-4' => [
                        "{$nsOrg}\\{$nsMod}\\" => 'src/',
                    ],
                ],
                'require' => [
                    'php' => '^8.2',
                ],
                'minimum-stability' => 'dev',
                'prefer-stable'     => true,
            ];

            $written = file_put_contents(
                $moduleComposer,
                json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
            );

            if ($written === false) {
                $spin->fail("Could not write {$moduleComposer}");
                $progress->finish('Aborted');
                return self::FAILURE;
            }

            $spin->stop("composer.json created");
        } else {
            $spin->stop("composer.json already present — skipped");
        }

        $progress->advance();

        /* ── Step 5: patch root composer.json ────────────── */
        $patchResult = $this->patchRootComposer($modulePath, $packageName);

        if ($patchResult !== null) {
            $progress->finish('Aborted');
            $this->alertError('Failed to patch root composer.json', [$patchResult]);
            return self::FAILURE;
        }

        $progress->advance();

        /* ── Step 6: composer update ──────────────────────── */
        $composerEnv = $offline ? ['COMPOSER_DISABLE_NETWORK' => '1'] : [];
        $composerCmd = $offline
            ? 'composer update --no-dev'
            : 'composer update';

        $result = $this->runWithSpinner(
            label:   $offline ? 'composer update (offline)' : 'composer update',
            command: $composerCmd,
            env:     $composerEnv,
            style:   'arc',
        );

        if ($result->failed()) {
            $progress->finish('Aborted');
            $this->alertError(
                'composer update failed',
                $result->meaningfulErrors()
            );
            return self::FAILURE;
        }

        $progress->advance();
        $progress->finish('All steps complete');

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
     * Runs $command under a SpinnerComponent.
     * The spinner's subLabel is continuously updated with the most recent
     * output line so the user sees live progress without scrolling output.
     */
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

        return $result;
    }

    /**
     * Check whether $modulePath is already registered in .gitmodules.
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
     * Adds a path repository entry and a require entry to the root composer.json.
     * Creates a .bak backup first, exactly as the bash script does.
     *
     * @return string|null null on success, error message string on failure
     */
    private function patchRootComposer(string $modulePath, string $packageName): ?string
    {
        $spin = $this->spinner('Patching root composer.json');
        $spin->start();

        $composerFile = 'composer.json';

        if (!is_file($composerFile)) {
            $spin->fail('root composer.json not found');
            return "File not found: {$composerFile}";
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
            return "composer.json contains invalid JSON";
        }

        // Backup (mirrors `cp composer.json composer.json.bak`)
        file_put_contents("{$composerFile}.bak", $raw);

        // De-duplicate then add the path repository (mirrors the jq logic)
        $repos = array_values(array_filter(
            (array) ($data['repositories'] ?? []),
            fn ($r) => is_array($r) && ($r['url'] ?? '') !== $modulePath
        ));
        $repos[] = ['type' => 'path', 'url' => $modulePath];
        $data['repositories'] = $repos;

        // Add require entry
        if (!isset($data['require'])) {
            $data['require'] = [];
        }
        $data['require'][$packageName] = '*';

        $written = file_put_contents(
            $composerFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );

        if ($written === false) {
            $spin->fail('Could not write composer.json');
            return "Cannot write {$composerFile}";
        }

        $spin->stop('root composer.json patched');
        return null;
    }

    /**
     * Convert kebab-case or snake_case to PascalCase.
     * Mirrors: echo "$1" | sed -E 's/(^|-)([a-z])/\U\2/g'
     *
     * Examples:
     *   user-auth    → UserAuth
     *   my_org       → MyOrg
     *   alfacode-team → AlfacodeTeam
     */
    private function toPascalCase(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }
}