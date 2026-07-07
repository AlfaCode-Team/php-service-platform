<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Seed;

use AlfaCode\LetMigrate\Seeder\SeederRunner;
use AlfacodeTeam\PhpIoCli\AbstractCommand;

/**
 * Shared base for seeder commands.
 * Mirrors the pattern used by AbstractMigrateCommand.
 */
abstract class AbstractSeedCommand extends AbstractCommand
{
    private SeederRunner $seederRunner;

    final public function withRunner(SeederRunner $runner): static
    {
        $this->seederRunner = $runner;
        return $this;
    }

    final protected function runner(): SeederRunner
    {
        if (!isset($this->seederRunner)) {
            throw new \LogicException(
                static::class . ' requires a SeederRunner injected via withRunner().',
            );
        }
        return $this->seederRunner;
    }
}
