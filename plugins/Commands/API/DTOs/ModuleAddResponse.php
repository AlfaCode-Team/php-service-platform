<?php

declare(strict_types=1);

namespace Plugins\Commands\API\DTOs;

final readonly class ModuleAddResponse
{
    public function __construct(
        public bool $success,
        public string $message,
        public string $modulePath,
        public string $packageName,
        public string $namespace,
        public ?string $error = null,
    ) {}

    public static function success(string $modulePath, string $packageName, string $namespace): self
    {
        return new self(
            success: true,
            message: "Module added successfully",
            modulePath: $modulePath,
            packageName: $packageName,
            namespace: $namespace,
        );
    }

    public static function failed(string $error): self
    {
        return new self(
            success: false,
            message: "Failed to add module",
            modulePath: '',
            packageName: '',
            namespace: '',
            error: $error,
        );
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'module_path' => $this->modulePath,
            'package_name' => $this->packageName,
            'namespace' => $this->namespace,
            'error' => $this->error,
        ];
    }
}
