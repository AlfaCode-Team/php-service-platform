<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * SmsPort — the ONLY way modules send SMS.
 * The kernel defines this interface; the project provides the adapter.
 */
interface SmsPort
{
    public function send(string $to, string $message): void;
}
