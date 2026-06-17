<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Error\Notifiers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Error\Contracts\NotifierContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\ErrorContext;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * DatabaseErrorLogger — persists errors to an `error_logs` table via DatabasePort.
 *
 * Never throws: a logging failure must not cascade into another error while we
 * are already handling one. The FileNotifier fallback covers that case.
 *
 * Expected schema (project migration):
 *   error_logs(id, correlation_id, severity, exception_class, message, layer,
 *              context JSON, request_method, request_path, user_id, occurred_at)
 */
final class DatabaseErrorLogger implements NotifierContract
{
    public function __construct(
        private readonly DatabasePort $db,
        private readonly string $table = 'error_logs',
    ) {}

    public function name(): string
    {
        return 'database';
    }

    public function notify(ErrorContext $context): void
    {
        try {
            $this->db->execute(
                "INSERT INTO {$this->table}
                    (correlation_id, severity, exception_class, message, layer,
                     context, request_method, request_path, user_id, occurred_at)
                 VALUES
                    (:correlation_id, :severity, :exception_class, :message, :layer,
                     :context, :request_method, :request_path, :user_id, :occurred_at)",
                [
                    'correlation_id'  => $context->correlationId,
                    'severity'        => $context->severity,
                    'exception_class' => $context->exceptionClass,
                    'message'         => $context->message,
                    'layer'           => $context->layer,
                    'context'         => json_encode($context->context),
                    'request_method'  => $context->requestMethod,
                    'request_path'    => $context->requestPath,
                    'user_id'         => $context->userId,
                    'occurred_at'     => $context->occurredAt,
                ],
            );
        } catch (\Throwable) {
            // Swallow — never error while handling an error.
        }
    }
}
