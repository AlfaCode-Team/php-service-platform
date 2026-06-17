<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * MailPort — the ONLY way modules send mail.
 * The kernel defines this interface; the project provides the adapter.
 */
interface MailPort
{
    /**
     * @param string|string[]      $to
     * @param array<string, mixed> $data
     */
    public function send(string|array $to, string $subject, string $view, array $data = []): void;

    /**
     * @param string|string[]      $to
     * @param array<string, mixed> $data
     * @return string queued message id
     */
    public function queue(string|array $to, string $subject, string $view, array $data = []): string;
}
