<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Error\Notifiers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Error\Contracts\NotifierContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\ErrorContext;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MailPort;

/**
 * MailNotifier — emails critical errors to the on-call recipients via MailPort.
 *
 * Never throws — mail delivery failure must not cascade.
 */
final class MailNotifier implements NotifierContract
{
    /** @param string|string[] $recipients */
    public function __construct(
        private readonly MailPort      $mail,
        private readonly string|array  $recipients,
        private readonly string        $view = 'errors.alert',
    ) {}

    public function name(): string
    {
        return 'mail';
    }

    public function notify(ErrorContext $context): void
    {
        try {
            $this->mail->send(
                $this->recipients,
                sprintf('[%s] %s', strtoupper($context->severity), $context->exceptionClass),
                $this->view,
                $context->toArray(),
            );
        } catch (\Throwable) {
            // Swallow — the FileNotifier fallback still records the error.
        }
    }
}
