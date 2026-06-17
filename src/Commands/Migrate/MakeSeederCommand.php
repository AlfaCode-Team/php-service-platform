<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;

use AlfacodeTeam\PhpIoCli\Components\TextInput;

final class MakeSeederCommand extends LetMigrateCommand
{
    protected function configure(): void
    {
        $this->name        = 'make:seeder';
        $this->description = 'Scaffold a new seeder class';

        $this->addArgument('name', 'Seeder class name (PascalCase)');
        $this->registerCommonOptions(withJson: false);
        $this->addOption('path', "p", 'Override seeders directory', acceptsValue: true);
    }

    protected function handle(): int
    {
        $name = (string) ($this->argument('name') ?? '');

        if ($name === '') {
            $name = (string) (new TextInput('Seeder name (PascalCase)'))
                ->placeholder('e.g. UsersSeeder')
                ->validate(static fn(string $v): ?string => preg_match('/^[A-Z][A-Za-z0-9]*$/', $v)
                    ? null
                    : 'Use PascalCase, e.g. UsersSeeder.')
                ->run();
        }

        $dir = (string) ($this->option('path')
            ?? $this->config()['seeders_path']
            ?? getcwd() . '/database/seeders');

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->error("Cannot create seeders directory: {$dir}");
            return self::FAILURE;
        }

        $path = $dir . DIRECTORY_SEPARATOR . "{$name}.php";

        $stub = <<<PHP
<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Seeder\SeederInterface;

final class {$name} implements SeederInterface
{
    public function run(DatabaseDriverInterface \$db): void
    {
        // \$db->insert('users', ['name' => 'Alice', 'email' => 'a@example.test']);
    }

    public function getDependencies(): array
    {
        return [];
    }
}
PHP;

        if (file_put_contents($path, $stub) === false) {
            $this->error("Could not write {$path}");
            return self::FAILURE;
        }

        $this->alertSuccess('Seeder created', [
            "Class: {$name}",
            "Path:  {$path}",
        ]);

        return self::SUCCESS;
    }
}