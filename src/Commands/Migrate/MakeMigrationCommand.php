<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;

use AlfacodeTeam\PhpIoCli\Components\TextInput;
 
final class MakeMigrationCommand extends LetMigrateCommand
{
    protected function configure(): void
    {
        $this->name        = 'make:migration';
        $this->description = 'Scaffold a new migration file';
 
        $this->addArgument('name', 'Migration name in snake_case');
        $this->registerCommonOptions(withJson: false);
        $this->addOption('path', "p", 'Override migrations directory',
            acceptsValue: true);
    }
 
    protected function handle(): int
    {
        $name = (string) ($this->argument('name') ?? '');
 
        // Interactive fallback — TextInput with snake_case validation.
        if ($name === '') {
            $name = (string) (new TextInput('Migration name (snake_case)'))
                ->placeholder('e.g. create_users_table')
                ->validate(static fn(string $v): ?string => preg_match('/^[a-z][a-z0-9_]*$/', $v)
                    ? null
                    : 'Use snake_case: lowercase letters, digits, underscores.')
                ->run();
        }
 
        $config = $this->config();
        $dir = (string) ($this->option('path')
            ?? $config['migrations_path']
            ?? $config['path']
            ?? ($config['paths'][0] ?? null)
            ?? getcwd() . '/database/migrations');
 
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->error("Cannot create migrations directory: {$dir}");
            return self::FAILURE;
        }
 
        $timestamp = date('Y_m_d_His');
        $filename  = "{$timestamp}_{$name}.php";
        $path      = $dir . DIRECTORY_SEPARATOR . $filename;
        $className = $this->classNameFor($name);
 
        $stub = <<<PHP
<?php
 
declare(strict_types=1);
 
use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;
 
return new class implements MigrationInterface {
 
    public function up(\$schema): void
    {
        // \$schema->create('table_name', function (Blueprint \$table) {
        //     \$table->id();
        //     \$table->timestamps();
        // });
    }
 
    public function down(\$schema): void
    {
        // \$schema->dropIfExists('table_name');
    }
};
PHP;
 
        if (file_put_contents($path, $stub) === false) {
            $this->error("Could not write {$path}");
            return self::FAILURE;
        }
 
        $this->alertSuccess('Migration created', [
            "File: {$filename}",
            "Path: {$path}",
        ]);
 
        return self::SUCCESS;
    }
 
    private function classNameFor(string $snake): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $snake)));
    }
}
 