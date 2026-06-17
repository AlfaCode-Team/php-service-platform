<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\SecurityLayerContract;

/** Verify the SecurityGateway has at least one valid layer registered. */
final class BindSecurityStage implements BootStageContract
{
    /** @param SecurityLayerContract[] $layers */
    public function __construct(private readonly array $layers) {}

    public function run(): void
    {
        if ($this->layers === []) {
            throw new BootException(
                "No security layers configured.\n"
                . "Register at least one via ->withSecurity([...]) in bootstrap/app.php."
            );
        }

        foreach ($this->layers as $layer) {
            if (!$layer instanceof SecurityLayerContract) {
                throw new BootException(
                    "Security layer [" . get_debug_type($layer) . "] must implement SecurityLayerContract."
                );
            }
        }
    }
}
