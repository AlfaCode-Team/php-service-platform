<?php

declare(strict_types=1);

namespace Plugins\Commands\API\DTOs;

use AlfacodeTeam\PhpIoCli\AbstractCommand;

final readonly class ModuleRemoveRequest
{
    public function __construct(
        public string $name,           // kebab-case
        public bool $force = false,    // Skip confirmations
    ) {
        $this->validate();
    }

    public static function fromInput(AbstractCommand $command): self
    {
        return new self(
            name: (string) $command->argument('name'),
            force: $command->hasOption('force'),
        );
    }

    private function validate(): void
    {
        if (empty($this->name)) {
            throw new \DomainException('Module name cannot be empty');
        }
        if (!preg_match('/^[a-z0-9\-]+$/', $this->name)) {
            throw new \DomainException('Module name must be kebab-case (a-z, 0-9, hyphens only)');
        }
    }

    public function getModulePath(): string
    {
        return "modules/{$this->name}";
    }
}
