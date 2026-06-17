<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Error\Notifiers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Error\Contracts\NotifierContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\ErrorContext;

/**
 * SlackNotifier — posts a compact alert to a Slack incoming webhook.
 *
 * Never throws. Network failures are swallowed so the guaranteed FileNotifier
 * fallback still records the error.
 */
final class SlackNotifier implements NotifierContract
{
    public function __construct(
        private readonly string $webhookUrl,
        private readonly int    $timeoutSeconds = 3,
    ) {}

    public function name(): string
    {
        return 'slack';
    }

    public function notify(ErrorContext $context): void
    {
        if ($this->webhookUrl === '') {
            return;
        }

        $text = sprintf(
            "*[%s]* `%s`\n%s\nlayer: %s | request: %s %s | id: %s",
            strtoupper($context->severity),
            $context->exceptionClass,
            $context->message,
            $context->layer ?: 'n/a',
            $context->requestMethod ?: '-',
            $context->requestPath ?: '-',
            $context->correlationId ?: '-',
        );

        $payload = json_encode(['text' => $text]);

        $ch = curl_init($this->webhookUrl);
        if ($ch === false) {
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
        ]);
        @curl_exec($ch);
        curl_close($ch);
    }
}
