<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts;

interface IntegrationEventContract
{
    public function name(): string;
    public function version(): string;
    public function payload(): array;
}
