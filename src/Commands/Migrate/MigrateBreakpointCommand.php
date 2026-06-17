<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;


use AlfaCode\LetMigrate\BreakpointStore;

final class MigrateBreakpointCommand extends LetMigrateCommand
{
    protected function configure(): void
    {
        $this->name        = 'migrate:breakpoint';
        $this->description = 'Set, clear, or list rollback breakpoints (production safety rail)';

        $this->addArgument('migration', 'Target migration (default: latest applied)');
        $this->registerCommonOptions();
        $this->addOption('set',   "s", 'Set the breakpoint (default if neither flag is given)');
        $this->addOption('unset', "u", 'Clear the breakpoint');
        $this->addOption('list',  "l", 'List every current breakpoint');
    }

    protected function handle(): int
    {
        $store = new BreakpointStore(
            $this->service()->driver(),
            ($this->config()['prefix'] ?? '') . 'let_breakpoints',
        );

        // --list
        if ($this->hasOption('list')) {
            $all = $store->all();
            if ($this->wantsJson()) {
                $this->emitJson(['breakpoints' => $all]);
                return self::SUCCESS;
            }
            if ($all === []) {
                $this->info('No breakpoints set.');
                return self::SUCCESS;
            }
            $this->section('Active breakpoints');
            foreach ($all as $m) {
                $this->info("  • {$m}");
            }
            return self::SUCCESS;
        }

        // resolve the target
        $target = (string) ($this->argument('migration') ?? '');
        if ($target === '') {
            $applied = array_filter($this->service()->status(),
                static fn($r) => ($r['status'] ?? '') === 'applied');
            $target  = (string) array_key_last($applied);
        }
        if ($target === '') {
            $this->error('No applied migration to mark.');
            return self::FAILURE;
        }

        if ($this->hasOption('unset')) {
            $store->clear($target);
            $this->success("Breakpoint cleared: {$target}");
        } else {
            $store->set($target);
            $this->success("Breakpoint set: {$target}");
        }

        return self::SUCCESS;
    }
}