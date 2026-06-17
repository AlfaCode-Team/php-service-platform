<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{CachePort, DatabasePort};

/** Verify all required port -> adapter bindings are present in CoreContainer. */
final class RegisterPortsStage implements BootStageContract
{
    public function __construct(private readonly CoreContainer $core) {}

    public function run(): void
    {
        $required = [DatabasePort::class, CachePort::class];
        $missing = [];
        foreach ($required as $port) {
            if (!$this->core->has($port)) {
                $missing[] = $port;
            }
        }
        if ($missing !== []) {
            throw new BootException(
                "Missing port bindings: " . implode(', ', $missing)
                . "\nBind them in bootstrap/app.php via ->withPorts([...])"
            );
        }
    }
}
