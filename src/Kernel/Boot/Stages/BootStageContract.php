<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;

interface BootStageContract
{
    /** @throws BootException on validation failure */
    public function run(): void;
}
