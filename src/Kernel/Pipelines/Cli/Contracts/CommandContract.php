<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\{Arguments, Output};

/**
 * @deprecated This interface is no longer used by CliPipeline.
 *             Commands must extend \AlfacodeTeam\PhpIoCli\AbstractCommand instead.
 *             Register via $cli->command(MyCommand::class) in Provider::boot().
 */
interface CommandContract
{
    public function handle(Arguments $args, Output $out): int;
}
