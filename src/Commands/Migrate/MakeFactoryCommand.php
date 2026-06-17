<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;


use AlfacodeTeam\PhpIoCli\Components\Select;
use AlfacodeTeam\PhpIoCli\Components\TextInput;

final class MakeFactoryCommand extends LetMigrateCommand
{
    protected function configure(): void
    {
        $this->name        = 'make:factory';
        $this->description = 'Scaffold a data factory (Phase 3 EntityFactory)';

        $this->addArgument('table', 'Target table name');
        $this->registerCommonOptions(withJson: false);
        $this->addOption('path', "p", 'Override factories directory', acceptsValue: true);
        $this->addOption('locale', 'l', "Fake-data locale (en|es|fr)",
            acceptsValue: true, default: 'en');
    }

    protected function handle(): int
    {
        $table = (string) ($this->argument('table') ?? '');
        if ($table === '') {
            $table = (string) (new TextInput('Target table name'))
                ->placeholder('e.g. users')
                ->run();
        }

        $localeOpt = $this->option('locale');
        $locale    = (is_string($localeOpt) && in_array($localeOpt, ['en','es','fr'], true))
            ? $localeOpt
            : (string) (new Select('Fake-data locale', ['en', 'es', 'fr']))->run();

        $dir = (string) ($this->option('path')
            ?? $this->config()['factories_path']
            ?? getcwd() . '/database/factories');

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->error("Cannot create factories directory: {$dir}");
            return self::FAILURE;
        }

        $className = str_replace(' ', '', ucwords(str_replace('_', ' ', $table))) . 'Factory';
        $path      = $dir . DIRECTORY_SEPARATOR . "{$className}.php";

        $stub = <<<PHP
<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Seeder\Factory\EntityFactory;
use AlfaCode\LetMigrate\Seeder\Factory\FakeData;

return EntityFactory::for('{$table}')
    ->definition(fn(FakeData \$f, int \$i) => [
        'id'    => \$i + 1,
        // 'name'  => \$f->name(),
        // 'email' => \$f->uniqueEmail(\$i),
    ])
    ->locale('{$locale}')
    ->count(10);
PHP;

        if (file_put_contents($path, $stub) === false) {
            $this->error("Could not write {$path}");
            return self::FAILURE;
        }

        $this->alertSuccess('Factory created', [
            "Class: {$className}",
            "Path:  {$path}",
            "Locale: {$locale}",
        ]);

        return self::SUCCESS;
    }
}