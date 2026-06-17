<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Error;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\FrameworkException;

/**
 * ErrorContext — a normalised, immutable snapshot of a failure.
 *
 * Everything the ErrorPipeline and notifiers need is captured here once, at the
 * catch site, so notifiers never touch the live request or the raw throwable.
 */
final readonly class ErrorContext
{
    /**
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $trace
     */
    public function __construct(
        public string  $exceptionClass,
        public string  $message,
        public string  $severity,        // 'critical' | 'warning' | 'info'
        public string  $layer,
        public array   $context,
        public ?string $correlationId,
        public ?string $requestPath,
        public ?string $requestMethod,
        public ?string $userId,
        public array   $trace,
        public ?string $previousClass,
        public string  $occurredAt,
    ) {}

    public static function fromThrowable(
        \Throwable $e,
        ?string $correlationId = null,
        ?string $requestPath = null,
        ?string $requestMethod = null,
        ?string $userId = null,
    ): self {
        $layer   = $e instanceof FrameworkException ? $e->layer : '';
        $context = $e instanceof FrameworkException ? $e->context : [];

        return new self(
            exceptionClass: $e::class,
            message:        $e->getMessage(),
            severity:       ErrorClassifier::severityFor($e),
            layer:          $layer,
            context:        $context,
            correlationId:  $correlationId,
            requestPath:    $requestPath,
            requestMethod:  $requestMethod,
            userId:         $userId,
            trace:          self::compactTrace($e),
            previousClass:  $e->getPrevious()?->getMessage() !== null ? $e->getPrevious()::class : null,
            occurredAt:     (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private static function compactTrace(\Throwable $e): array
    {
        $trace = [];
        foreach (array_slice($e->getTrace(), 0, 20) as $frame) {
            $trace[] = [
                'file'     => $frame['file'] ?? null,
                'line'     => $frame['line'] ?? null,
                'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''),
            ];
        }
        return $trace;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'exception'      => $this->exceptionClass,
            'message'        => $this->message,
            'severity'       => $this->severity,
            'layer'          => $this->layer,
            'context'        => $this->context,
            'correlation_id' => $this->correlationId,
            'request'        => [
                'method' => $this->requestMethod,
                'path'   => $this->requestPath,
                'user'   => $this->userId,
            ],
            'previous'       => $this->previousClass,
            'occurred_at'    => $this->occurredAt,
            'trace'          => $this->trace,
        ];
    }
}
