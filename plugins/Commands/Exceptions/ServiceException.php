<?php

declare(strict_types=1);

namespace Plugins\Commands\Exceptions;

final class ServiceException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $context = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function moduleAddFailed(string $reason): self
    {
        return new self("Failed to add module: {$reason}", context: 'module.add');
    }

    public static function moduleRemoveFailed(string $reason): self
    {
        return new self("Failed to remove module: {$reason}", context: 'module.remove');
    }

    public static function migrationFailed(string $reason): self
    {
        return new self("Migration failed: {$reason}", context: 'migration');
    }

    public static function lockAcquisitionFailed(string $reason): self
    {
        return new self("Could not acquire deployment lock: {$reason}", context: 'lock');
    }
}
