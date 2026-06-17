<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions;

class ValidationException extends FrameworkException
{
    /** @param array<string, string|string[]> $errors */
    public function __construct(
        public readonly array $errors,
        string $message = 'The request data is invalid.',
    ) {
        parent::__construct($message, layer: 'validation');
    }
}
