<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;


use AlfaCode\LetMigrate\Contract\MigrationServiceInterface;
use AlfaCode\LetMigrate\Contract\SchemaInspectorInterface;
use AlfaCode\LetMigrate\DriverRegistry;
use AlfaCode\LetMigrate\Event\MigrationEventDispatcher;
use AlfaCode\LetMigrate\MigrationServiceFactory;
use AlfaCode\LetMigrate\SchemaSnapshot;
use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpIoCli\Depends\Colors;
use Psr\Log\NullLogger;

/**
 * Shared base for every let-migrate command running on the php-io-cli runtime.
 *
 * Responsibilities
 * ────────────────
 *   • Load configuration from --config (required, no CWD auto-discovery).
 *   • Build a MigrationService via MigrationServiceFactory.
 *   • Own a single MigrationEventDispatcher that the service and any
 *     subclass-attached listeners share — so commands can react to every
 *     MigrationStarted / MigrationFinished / MigrationFailed event for
 *     live feedback (progress bars, per-migration status, telemetry).
 *   • Register the universal --connection / --config / --json options.
 *   • Helpers for emitting raw JSON (no colour wrapping) on --json paths.
 *
 * Subclasses only implement configure() and handle(). To react to events
 * during a run, override configureEvents() — it is called automatically
 * before the runner executes.
 */
abstract class LetMigrateCommand extends AbstractCommand
{
    private ?MigrationServiceInterface $cachedService = null;
 
    private ?DriverRegistry            $cachedRegistry = null;
 
    private ?MigrationEventDispatcher  $cachedEvents  = null;
 
    private array                      $cachedConfig  = [];
 
    /**
     * Constructor-injected baseline config.
     *
     * When present (set by CliCommandFactory::fromConfig()), the command does
     * not require --config=<path> on the CLI — the factory has already loaded
     * and normalised it. --config is still accepted at the CLI as an override.
     *
     * When null, --config is required on every invocation.
     *
     * @var array<string, mixed>|null
     */
    private ?array                     $injectedConfig = null;
 
    /**
     * Accept an optional pre-loaded config array.
     *
     * Typical use: a bootstrap script loads its config once and passes it to
     * CliCommandFactory::fromConfig(), which injects it into every command
     * instance via this constructor. Subclasses don't need to override this —
     * the default no-arg path still works for direct instantiation.
     *
     * @param array<string, mixed>|null $config
     */
    public function __construct(?array $config = null)
    {
        parent::__construct();
        $this->injectedConfig = $config;
    }
 
    /**
     * Subclasses call this first in their configure() to inherit
     * --connection / --config / --json without repeating themselves.
     *
     * --config remains accepted at the CLI as an override; commands
     * bootstrapped via CliCommandFactory::fromConfig() get their config
     * via constructor injection and don't require it.
     */
    protected function registerCommonOptions(bool $withJson = true): void
    {
        $this->addOption('connection', 'c', 'Database connection name',
            acceptsValue: true);
        $this->addOption('config',     '',
            'Path to let-migrate config file (overrides factory-injected config)',
            acceptsValue: true);
 
        if ($withJson) {
            $this->addOption('json', '',
                'Emit machine-readable JSON instead of human output');
        }
    }
 
    /**
     * Lazily resolve the MigrationService. Cached so multiple calls inside
     * handle() share a single connection AND a single event dispatcher.
     *
     * Verified factory contract (src/MigrationServiceFactory.php):
     *   fromConfig(array $config,
     *              LoggerInterface $logger = new NullLogger(),
     *              MigrationEventDispatcher|null $events = null)
     *       : MigrationServiceInterface
     *
     * Multi-connection is handled here, NOT by the factory (the factory has
     * no $connection parameter). If --connection=NAME is supplied AND the
     * config has a 'connections' map, we pick the named sub-config and
     * splice it onto the top level before handing to fromConfig().
     */
    protected function service(): MigrationServiceInterface
    {

        if ($this->cachedService !== null) {
            return $this->cachedService;
        }
 
        $config = $this->configForConnection($this->loadConfig());
 
        $events = $this->events();
        $this->configureEvents($events);
 
        $this->cachedService = MigrationServiceFactory::fromConfig(
            $config,
            new NullLogger(),
            $events,
        );

 
        return $this->cachedService;
    }
 
    /**
     * Resolve the right sub-config when --connection=NAME is supplied.
     *
     * Supports the convention used by Laravel-style configs:
     *
     *   return [
     *       'default'     => 'mysql',
     *       'connections' => [
     *           'mysql' => ['driver' => 'mysql', 'host' => …],
     *           'pgsql' => ['driver' => 'pgsql', 'host' => …],
     *       ],
     *       'paths' => [__DIR__ . '/migrations'],
     *   ];
     *
     * Without --connection, the 'default' name (or first connection) is used.
     * If the config has no 'connections' key, it's returned unchanged
     * (single-connection setup) and --connection is rejected with a clear
     * error instead of silently ignored.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function configForConnection(array $config): array
    {
        $requested = $this->option('connection');
        $requested = $requested !== null ? (string) $requested : null;
 
        // Single-connection config (no 'connections' map).
        if (!isset($config['connections']) || !is_array($config['connections'])) {
            if ($requested !== null) {
                $this->error(
                    "--connection={$requested} requested but config has no "
                    . "'connections' map. Either remove --connection or "
                    . "restructure config with ['default' => …, 'connections' => […]].",
                );
                throw new \RuntimeException('Multi-connection config required for --connection.');
            }
 
            return $config;
        }
 
        // Multi-connection config.
        $connections = $config['connections'];
        $name = $requested
            ?? (string) ($config['default'] ?? array_key_first($connections));
 
        if (!isset($connections[$name]) || !is_array($connections[$name])) {
            $available = implode(', ', array_keys($connections));
            $this->error("Unknown connection '{$name}'. Available: {$available}");
            throw new \RuntimeException("Unknown connection: {$name}");
        }
 
        // Splice the chosen connection onto the top level so the factory
        // sees a flat single-connection config; preserve top-level paths,
        // tracking_table, seeders_table, etc.
        $merged = $connections[$name] + array_diff_key($config, ['connections' => 1, 'default' => 1]);
 
        return $merged;
    }
 
    /**
     * Shared MigrationEventDispatcher. Built lazily on first access so that
     * subclass listeners attached BEFORE service() is constructed end up on
     * the same instance the runner will dispatch to.
     *
     * Subclasses typically don't call this directly — they override
     * configureEvents() instead. But it's available for ad-hoc listener
     * attachment from inside handle() if a command needs it.
     */
    protected function events(): MigrationEventDispatcher
    {
        return $this->cachedEvents ??= new MigrationEventDispatcher();
    }
 
    /**
     * Attach event listeners. Called once, automatically, just before the
     * runner is built. Subclasses override this to add progress-bar wiring,
     * per-migration status output, telemetry, error capture, etc.
     *
     * Default: no listeners attached (silent run, behaviour unchanged).
     *
     * Example (in a subclass):
     *   protected function configureEvents(MigrationEventDispatcher $e): void
     *   {
     *       $e->on(MigrationStarted::class,
     *           fn($evt) => $this->info("→ {$evt->migration}"));
     *       $e->on(MigrationFailed::class,
     *           fn($evt) => $this->error("✘ {$evt->migration}: "
     *                                    . $evt->exception->getMessage()));
     *   }
     */
    protected function configureEvents(MigrationEventDispatcher $events): void
    {
        // no-op by default — subclasses override to attach listeners
    }
 
    /**
     * Build and cache a DriverRegistry for the currently-selected connection.
     *
     * Used by generate/diff commands that need direct schema-inspector access
     * (the MigrationService doesn't expose one). Sharing the same per-command
     * cache means the inspector and the running service hit the same PDO
     * handle.
     */
    protected function registry(): DriverRegistry
    {
        return $this->cachedRegistry ??= DriverRegistry::fromConfig(
            $this->configForConnection($this->loadConfig()),
        );
    }
 
    /**
     * Build a schema inspector for the current connection. Shorthand for
     * $this->registry()->makeInspector() so commands can write:
     *   new MigrationGenerator($this->inspector())
     */
    protected function inspector(): SchemaInspectorInterface
    {
        return $this->registry()->makeInspector();
    }

    /**
     * Build the "target" schema snapshot — the structure the project's
     * migrations produce when applied to a clean database.
     *
     * Implementation: apply every migration to a throwaway file-based SQLite
     * database, then capture its schema with a fresh inspector. File-based
     * (not :memory:) so the inspector — a second connection — sees the tables
     * the migration run created. The scratch DB is deleted afterwards.
     *
     * Used by migrate:diff (reconciliation migration) and migrate:check (CI
     * drift guard) so both compare the live DB against the same target.
     *
     * @return array<string, array<string, mixed>> SchemaSnapshot-shaped array
     */
    protected function targetSnapshot(): array
    {
        $paths = (array) ($this->config()['paths'] ?? []);

        $scratchFile = tempnam(sys_get_temp_dir(), 'letmigrate_target_') ?: null;
        if ($scratchFile === null) {
            throw new \RuntimeException('Could not create a scratch database for the target snapshot.');
        }

        $scratch = [
            'driver'         => 'sqlite',
            'database'       => $scratchFile,
            'paths'          => $paths,
            'tracking_table' => 'let_migrations',
        ];

        try {
            $service = MigrationServiceFactory::fromConfig($scratch);
            $service->install();
            $service->run();

            $inspector = DriverRegistry::fromConfig($scratch)->makeInspector();

            return SchemaSnapshot::capture($inspector);
        } finally {
            @unlink($scratchFile);
        }
    }
 
    /**
     * --json flag predicate — used by every command to branch into the
     * structured-output path.
     */
    protected function wantsJson(): bool
    {
        return $this->hasOption('json');
    }
 
    /**
     * Emit a JSON payload directly to stdout (no colour wrapping, no icons).
     * Disables ANSI globally for the rest of the run so colour codes can't
     * sneak in via output helpers on a TTY.
     */
    protected function emitJson(mixed $data): void
    {
        Colors::disable();
        echo json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . PHP_EOL;
    }
 
    /**
     * Resolve the configuration array, with the following precedence:
     *
     *   1. --config=<path> from the CLI (always wins; lets ops override
     *      the bootstrap-injected config without rebuilding the binary)
     *   2. Constructor-injected config (set by CliCommandFactory::fromConfig)
     *   3. Fail with a clear "no config" error
     *
     * The result is passed through normalizeConfig() so that user-friendly
     * keys (migrations_path, seeders_path) are translated to the keys
     * MigrationConfig::fromArray() and friends expect (paths, etc.).
     *
     * @return array<string, mixed>
     */
    protected function loadConfig(): array
    {
        if ($this->cachedConfig !== []) {
            return $this->cachedConfig;
        }
 
        // 1. --config=<path> override (only if the option was registered;
        //    not all subclasses call registerCommonOptions).
        $path = $this->hasOption('config') ? $this->option('config') : null;
        if (is_string($path) && $path !== '') {
            $resolved = $this->resolveConfigPath($path);
            return $this->cachedConfig = $this->normalizeConfig(
                $this->loadConfigFile($resolved),
            );
        }
 
        // 2. Constructor-injected config (from the factory).
        if ($this->injectedConfig !== null) {
            return $this->cachedConfig = $this->normalizeConfig($this->injectedConfig);
        }
 
        // 3. Nothing available.
        throw new \RuntimeException(
            'No configuration available. Either pass --config=<path> on the '
            . 'command line, or bootstrap commands via '
            . 'CliCommandFactory::fromConfig($config).',
        );
    }
 
    /**
     * Public, cache-aware accessor — used by subclasses that need to read
     * raw config keys (e.g. paths, prefix) without triggering service
     * construction.
     *
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        return $this->loadConfig();
    }
 
    /**
     * Translate user-friendly config keys into the canonical names used by
     * MigrationConfig::fromArray() and DriverRegistry::fromConfig().
     *
     * Mappings applied (only when the canonical key is absent):
     *
     *   migrations_path  (string)        → path           (string)
     *   migrations_paths (string[])      → paths          (string[])
     *
     * Other user keys (seeders_path, factories_path, schema_dump,
     * all_or_nothing, prefix, tenants) are passed through unchanged — they
     * are consumed by command-layer code via $this->config().
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function normalizeConfig(array $config): array
    {
        // migrations_path (singular) → path (alias of paths)
        if (isset($config['migrations_path']) && !isset($config['path']) && !isset($config['paths'])) {
            $config['path'] = (string) $config['migrations_path'];
        }
        // migrations_paths (plural array) → paths
        if (isset($config['migrations_paths']) && !isset($config['paths'])) {
            $config['paths'] = (array) $config['migrations_paths'];
        }
 
        return $config;
    }
 
    /**
     * Read and validate a config file from disk.
     *
     * @return array<string, mixed>
     */
    private function loadConfigFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Config not found: {$path}");
        }
 
        /** @var mixed $loaded */
        $loaded = require $path;
 
        if (!is_array($loaded)) {
            throw new \RuntimeException("Config at {$path} must return an array.");
        }
 
        return $loaded;
    }
 
    /**
     * Resolve relative --config paths against CWD so `--config=config/x.php`
     * works the way every shell user expects.
     */
    private function resolveConfigPath(string $path): string
    {
        if (!str_starts_with($path, '/') && !preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return getcwd() . DIRECTORY_SEPARATOR . $path;
        }
        return $path;
    }
}
 