<?php

declare(strict_types=1);

namespace Plugins\Commands\API\DTOs;

use AlfacodeTeam\PhpIoCli\AbstractCommand;

final readonly class ModuleAddRequest
{
    public function __construct(
        public string $name,           // kebab-case
        public string $gitUrl,
        public string $org,            // vendor name
        public bool $offline = false,  // Install without network
    ) {
        $this->validate();
    }

    public static function fromInput(AbstractCommand $command): self
    {
        return new self(
            name: (string) $command->argument('name'),
            gitUrl: (string) $command->argument('git-url'),
            org: (string) $command->argument('org'),
            offline: $command->hasOption('offline'),
        );
    }

    private function validate(): void
    {
        if (empty($this->name)) {
            throw new \DomainException('Module name cannot be empty');
        }
        if (empty($this->gitUrl)) {
            throw new \DomainException('Git URL cannot be empty');
        }
        if (empty($this->org)) {
            throw new \DomainException('Organization name cannot be empty');
        }
        if (!preg_match('/^[a-z0-9\-]+$/', $this->name)) {
            throw new \DomainException('Module name must be kebab-case (a-z, 0-9, hyphens only)');
        }
    }

    public function toPascalCase(string $text): string
    {
        return str_replace(
            ' ',
            '',
            ucwords(str_replace('-', ' ', $text))
        );
    }

    public function getPackageName(): string
    {
        return "{$this->org}/{$this->name}";
    }

    public function getNamespace(): string
    {
        return $this->toPascalCase($this->org) . '\\' . $this->toPascalCase($this->name) . '\\';
    }

    public function getModulePath(): string
    {
        return "modules/{$this->name}";
    }
}
