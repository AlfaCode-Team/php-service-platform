<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;


final class DbSeedCommand extends LetMigrateCommand
{
    protected function configure(): void
    {
        $this->name        = 'db:seed';
        $this->description = 'Run database seeders';

        $this->registerCommonOptions(withJson: false);
        $this->addOption('class', "C",
            'Run only a specific seeder class', acceptsValue: true);
    }

    protected function handle(): int
    {
        $class   = $this->option('class');
        $service = $this->service();

        $bar = $this->progressBar('Seeding');
        $bar->start();

        try {
            $count = $service->seed(is_string($class) ? $class : null);
            $bar->finish('Seeding complete');
        } catch (\Throwable $e) {
            $bar->finish('Seeding failed');
            $this->alertError('Seeder failed', [$e->getMessage()]);
            return self::FAILURE;
        }

        $this->alertSuccess('Seeders run', [
            'Inserted records: ' . (is_int($count) ? $count : 'n/a'),
        ]);

        return self::SUCCESS;
    }
}