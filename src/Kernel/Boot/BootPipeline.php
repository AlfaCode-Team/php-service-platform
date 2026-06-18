<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\BootFailureException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\{
    BootStageContract,
    ValidateConfigStage,
    DetectConflictsStage,
    DetectCyclesStage,
    CompileServiceManifestStage,
    CompileRouteManifestStage,
    CompileViewManifestStage,
    CompileJobManifestStage,
    CompileCommandManifestStage,
    RegisterPortsStage,
    BindSecurityStage
};

/**
 * BootPipeline — runs ONCE at startup.
 *
 * Stages run in fixed order. Any failure = immediate shutdown with a
 * descriptive BootFailureException listing exactly what is wrong.
 * The application never starts with missing config, conflicting modules,
 * circular dependencies, or unbound ports.
 */
final class BootPipeline
{
    /** @var list<BootStageContract> Ordered boot stages — order is fixed and meaningful. */
    private array $stages;

    /**
     * @param list<class-string> $moduleClasses
     * @param array<int, \AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\SecurityLayerContract> $securityLayers
     * @param list<array{method: string, path: string, handler: string}> $projectRoutes
     *   Project-layer routes (declared via Kernel::withRoutes), compiled into the
     *   route manifest under the synthetic '__project__' scope with no module graph.
     */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly CoreContainer $core,
        array $securityLayers = [],
        array $projectRoutes = [],
    ) {
        $this->stages = [
            new ValidateConfigStage($moduleClasses),         // 1. env vars present + typed
            new DetectConflictsStage($moduleClasses),        // 2. no two modules share solves()
            new DetectCyclesStage($moduleClasses),           // 3. no circular requires[] chains
            new CompileServiceManifestStage($moduleClasses, projectRoutes: $projectRoutes), // 4. dep graph → service-manifest.php
            new CompileRouteManifestStage($moduleClasses, projectRoutes: $projectRoutes),   // 5. routes[] → route-manifest.php
            new CompileViewManifestStage($moduleClasses),    // 6. views[] → view-manifest.php (project-first cascade)
            new CompileJobManifestStage($moduleClasses),     // 7. jobs[] → job-manifest.php
            new CompileCommandManifestStage($moduleClasses), // 8. commands[] → command-manifest.php
            new RegisterPortsStage($core),                   // 9. Port → Adapter bindings validated
            new BindSecurityStage($securityLayers),          // 10. SecurityGateway layers validated
        ];
    }

    /**
     * Run all stages in order. Fail fast on any error.
     *
     * @throws BootFailureException with the stage name and reason
     */
    public function run(): void
    {
        foreach ($this->stages as $stage) {
            try {
                $stage->run();
            } catch (BootException $e) {
                throw new BootFailureException(
                    "Boot failed at [" . $stage::class . "]: " . $e->getMessage(),
                    previous: $e,
                );
            }
        }
    }
}
