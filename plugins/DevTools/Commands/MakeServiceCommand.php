<?php

declare(strict_types=1);

namespace Plugins\DevTools\Commands;

use AlfacodeTeam\PhpIoCli\Components\TextInput;

/**
 * Generate a single Application service class inside an existing plugin.
 *
 * Usage: make:service Invoice Refund
 *   -> plugins/Invoice/Application/Services/RefundService.php
 */
final class MakeServiceCommand extends GeneratorCommand
{
    protected function configure(): void
    {
        $this->name = 'make:service';
        $this->description = 'Generate an Application service inside a plugin';

        $this->addArgument('plugin', 'Target plugin name (StudlyCase)');
        $this->addArgument('name', 'Service name without the "Service" suffix');
        $this->addOption('force', 'f', 'Overwrite if it exists');
    }

    protected function handle(): int
    {
        $plugin = $this->studly((string) ($this->argument('plugin') ?? ''));
        $name   = (string) ($this->argument('name') ?? '');

        if ($plugin === '') {
            $this->error('A target plugin name is required.');
            return self::FAILURE;
        }
        if ($name === '') {
            $name = (new TextInput('Service name'))->placeholder('e.g. Refund')->run();
        }

        $studly = $this->studly($name);
        $ns     = "Plugins\\{$plugin}\\Application\\Services";
        $path   = $this->pluginsRoot() . "/{$plugin}/Application/Services/{$studly}Service.php";

        $stub = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$ns};

        final class {$studly}Service
        {
            public function __construct()
            {
            }
        }

        PHP;

        return $this->writeFile($path, $stub, (bool) $this->hasOption('force'))
            ? self::SUCCESS
            : self::FAILURE;
    }
}
