<?php

declare(strict_types=1);

namespace Plugins\Commands\API\DTOs;

use AlfacodeTeam\PhpIoCli\AbstractCommand;

final readonly class MigrateStatusRequest
{
    public function __construct(
        public ?string $configPath = null,
    ) {}

    public static function fromInput(AbstractCommand $command): self
    {
        return new self(
            configPath: $command->option('config') ? (string) $command->option('config') : null,
        );
    }
}
