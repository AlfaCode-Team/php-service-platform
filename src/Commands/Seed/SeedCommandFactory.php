<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Seed;

use AlfaCode\LetMigrate\MigrationConfig;
use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\DriverRegistry;
use AlfaCode\LetMigrate\Schema\GrammarInterface;
use AlfaCode\LetMigrate\Seeder\SeederRepository;
use AlfaCode\LetMigrate\Seeder\SeederRunner;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Factory that constructs all seeder CLI commands pre-wired with a SeederRunner.
 *
 * Mirrors MigrationCommandFactory in structure and intent.
 *
 * Usage:
 *
 *   $factory = SeedCommandFactory::fromConfig([
 *       'driver'       => 'mysql',
 *       'host'         => '127.0.0.1',
 *       'database'     => 'my_app',
 *       'username'     => 'root',
 *       'password'     => 'secret',
 *       'seeders_path' => __DIR__ . '/seeders',
 *   ]);
 *
 *   (new CLIApplication('MyApp', '1.0.0'))
 *       ->add(...$factory->all())
 *       ->run();
 *
 * Or combine with MigrationCommandFactory:
 *
 *   $migrateFactory = MigrationCommandFactory::fromConfig($config);
 *   $seedFactory    = SeedCommandFactory::fromConfig($config);
 *
 *   $app->add(...$migrateFactory->all(), ...$seedFactory->all());
 */
final class SeedCommandFactory
{
    private readonly SeederRunner $runner;

    public function __construct(SeederRunner $runner)
    {
        $this->runner = $runner;
    }

    // ── Static factory helpers ────────────────────────────────────

    /**
     * Build from a flat config array.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(
        array           $config,
        LoggerInterface $logger = new NullLogger(),
    ): self {
        $registry = DriverRegistry::fromConfig($config);
        $cfg      = MigrationConfig::fromArray($config);
        $driver   = $registry->driver();
        $grammar  = $registry->grammar();

        $runner = self::buildRunner($driver, $grammar, $cfg, $logger);

        return new self($runner);
    }

    /**
     * Build from a pre-built DriverRegistry.
     *
     * @param array<string, mixed> $config
     */
    public static function fromRegistry(
        DriverRegistry  $registry,
        array           $config  = [],
        LoggerInterface $logger  = new NullLogger(),
    ): self {
        $cfg    = MigrationConfig::fromArray($config);
        $driver = $registry->driver();
        $grammar = $registry->grammar();
        $runner = self::buildRunner($driver, $grammar, $cfg, $logger);

        return new self($runner);
    }

    /**
     * Build directly from an existing SeederRunner.
     * Useful in tests or custom DI setups.
     */
    public static function fromRunner(SeederRunner $runner): self
    {
        return new self($runner);
    }

    // ── Command getters ───────────────────────────────────────────

    /**
     * Return all seeder commands pre-wired with the SeederRunner.
     *
     * @return AbstractSeedCommand[]
     */
    public function all(): array
    {
        return [
            $this->run(),
            $this->fresh(),
            $this->status(),
        ];
    }

    public function run(): SeedRunCommand
    {
        return (new SeedRunCommand())->withRunner($this->runner);
    }

    public function fresh(): SeedFreshCommand
    {
        return (new SeedFreshCommand())->withRunner($this->runner);
    }

    public function status(): SeedStatusCommand
    {
        return (new SeedStatusCommand())->withRunner($this->runner);
    }

    public function getRunner(): SeederRunner
    {
        return $this->runner;
    }

    // ── Internal builder ──────────────────────────────────────────

    private static function buildRunner(
        DatabaseDriverInterface $driver,
        GrammarInterface        $grammar,
        MigrationConfig         $cfg,
        LoggerInterface         $logger,
    ): SeederRunner {
        $repository = new SeederRepository(
            driver:  $driver,
            grammar: $grammar,
            table:   $cfg->seedersTable,
        );

        $paths = $cfg->hasSeederPath()
            ? [$cfg->seedersPath]
            : [];

        return new SeederRunner(
            driver:     $driver,
            repository: $repository,
            paths:      $paths,
            logger:     $logger,
        );
    }
}
