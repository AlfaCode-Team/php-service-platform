<?php

declare(strict_types=1);

namespace Plugins\Commands\API\DTOs;

final readonly class MigrateResponse
{
    public function __construct(
        public bool $success,
        public string $message,
        public int $migrationsRun,
        public array $details = [],
        public ?string $error = null,
    ) {}

    public static function success(int $count = 0, array $details = []): self
    {
        return new self(
            success: true,
            message: "Migration completed successfully",
            migrationsRun: $count,
            details: $details,
        );
    }

    public static function failed(string $error, array $details = []): self
    {
        return new self(
            success: false,
            message: "Migration failed",
            migrationsRun: 0,
            details: $details,
            error: $error,
        );
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'migrations_run' => $this->migrationsRun,
            'details' => $this->details,
            'error' => $this->error,
        ];
    }
}
