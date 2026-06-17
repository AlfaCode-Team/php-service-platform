<?php

declare(strict_types=1);

namespace Plugins\Commands\API\DTOs;

final readonly class ModuleRemoveResponse
{
    public function __construct(
        public bool $success,
        public string $message,
        public string $moduleName,
        public ?string $error = null,
    ) {}

    public static function success(string $moduleName): self
    {
        return new self(
            success: true,
            message: "Module removed successfully",
            moduleName: $moduleName,
        );
    }

    public static function failed(string $moduleName, string $error): self
    {
        return new self(
            success: false,
            message: "Failed to remove module",
            moduleName: $moduleName,
            error: $error,
        );
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'module_name' => $this->moduleName,
            'error' => $this->error,
        ];
    }
}
