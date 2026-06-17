<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions;

abstract class FrameworkException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $layer = '',
        public readonly array $context = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
