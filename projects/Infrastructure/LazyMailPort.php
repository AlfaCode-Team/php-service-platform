<?php

declare(strict_types=1);

namespace Project\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MailPort;

/**
 * LazyMailPort — a MailPort that defers building the real mailer (and its SMTP
 * transport) until the first send.
 *
 * Used to wire the MailNotifier without constructing an SMTP transport at
 * bootstrap: the notifier only sends when an error of the configured severity
 * fires, so the underlying mailer stays unbuilt on the happy path. The resolved
 * port is memoised.
 */
final class LazyMailPort implements MailPort
{
    /** @var \Closure(): MailPort */
    private \Closure $factory;

    private ?MailPort $resolved = null;

    /** @param \Closure(): MailPort $factory */
    public function __construct(\Closure $factory)
    {
        $this->factory = $factory;
    }

    private function port(): MailPort
    {
        return $this->resolved ??= ($this->factory)();
    }

    public function send(string|array $to, string $subject, string $view, array $data = []): void
    {
        $this->port()->send($to, $subject, $view, $data);
    }

    public function queue(string|array $to, string $subject, string $view, array $data = []): string
    {
        return $this->port()->queue($to, $subject, $view, $data);
    }
}
