<?php

declare(strict_types=1);

namespace Plugins\Commands\API\DTOs;

use AlfacodeTeam\PhpIoCli\AbstractCommand;

final readonly class MigrateRequest
{
    public function __construct(
        public ?string $configPath = null,    // Override config path
        public bool $pretend = false,         // Preview without executing
        public int $steps = 0,                // Number of migrations to run/rollback
        public bool $force = false,           // Skip confirmations
        public ?string $targetVersion = null, // For migrate:to
    ) {}

    public static function fromInput(AbstractCommand $command): self
    {
        return new self(
            configPath: $command->option('config') ? (string) $command->option('config') : null,
            pretend: $command->hasOption('pretend'),
            steps: (int) ($command->option('steps') ?? 0),
            force: $command->hasOption('force'),
            targetVersion: $command->option('target') ? (string) $command->option('target') : null,
        );
    }
}
