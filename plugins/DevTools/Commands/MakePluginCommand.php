<?php

declare(strict_types=1);

namespace Plugins\DevTools\Commands;

use AlfacodeTeam\PhpIoCli\Components\TextInput;

/**
 * Scaffold a complete GDA plugin under plugins/{Name}/ with all layers wired:
 * module.json, Provider, contract, service, repository, controller, entity,
 * value object, DTOs and integration event.
 *
 * Usage: make:plugin Invoice --solves=invoice.generation
 */
final class MakePluginCommand extends GeneratorCommand
{
    protected function configure(): void
    {
        $this->name = 'make:plugin';
        $this->description = 'Scaffold a full GDA plugin (all layers) under plugins/';

        $this->addArgument('name', 'Plugin name in StudlyCase (e.g. Invoice)');
        $this->addOption('solves', 's', 'Domain the plugin solves (e.g. invoice.generation)', acceptsValue: true);
        $this->addOption('force', 'f', 'Overwrite existing files');
    }

    protected function handle(): int
    {
        $name = (string) ($this->argument('name') ?? '');
        if ($name === '') {
            $name = (new TextInput('Plugin name (StudlyCase)'))
                ->placeholder('e.g. Invoice')
                ->validate(static fn (string $v): ?string =>
                    preg_match('/^[A-Z][A-Za-z0-9]+$/', $v) ? null : 'Use StudlyCase, e.g. Invoice')
                ->run();
        }

        $studly = $this->studly($name);
        $snake  = $this->snake($studly);
        $kebab  = $this->kebab($studly);
        $solves = (string) ($this->option('solves') ?? ($snake . '.management'));
        $force  = (bool) $this->hasOption('force');
        $root   = $this->pluginsRoot() . '/' . $studly;
        $ns     = 'Plugins\\' . $studly;

        if (is_dir($root) && !$force) {
            $this->error("Plugin already exists: plugins/{$studly} (use --force to overwrite files)");
            return self::FAILURE;
        }

        $this->section("Scaffolding plugin: {$studly}");

        foreach ($this->files($studly, $ns, $solves, $kebab) as $rel => $contents) {
            $this->writeFile($root . '/' . $rel, $contents, $force);
        }

        $this->newLine();
        $this->alertSuccess("Plugin {$studly} created", [
            "Register it: add {$ns}\\Provider::class to a project bootstrap app.php",
            "Domain (solves): {$solves}",
        ]);

        return self::SUCCESS;
    }

    /**
     * @return array<string,string> relativePath => fileContents
     */
    private function files(string $studly, string $ns, string $solves, string $kebab): array
    {
        $contractFqcn = "{$ns}\\API\\Contracts\\{$studly}ServiceContract";

        return [
            'module.json' => $this->moduleJson($studly, $ns, $solves, $kebab),
            'Provider.php' => $this->provider($studly, $ns),
            "API/Contracts/{$studly}ServiceContract.php" => $this->contract($studly, $ns),
            "Application/Services/{$studly}Service.php" => $this->service($studly, $ns),
            "Domain/Entities/{$studly}.php" => $this->entity($studly, $ns),
            "Infrastructure/Persistence/{$studly}Repository.php" => $this->repository($studly, $ns),
            "Infrastructure/Http/{$studly}Controller.php" => $this->controller($studly, $ns),
        ];
    }

    private function moduleJson(string $studly, string $ns, string $solves, string $kebab): string
    {
        $contract = str_replace('\\', '\\\\', "{$ns}\\API\\Contracts\\{$studly}ServiceContract");
        $controller = str_replace('\\', '\\\\', "{$ns}\\Infrastructure\\Http\\{$studly}Controller");
        return <<<JSON
        {
            "name": "{$kebab}",
            "version": "1.0.0",
            "solves": "{$solves}",
            "type": "module",

            "requires": ["database.query"],
            "exposes": ["{$contract}"],

            "routes": [
                { "method": "GET",  "path": "/api/{$kebab}",      "handler": "{$controller}@index" },
                { "method": "GET",  "path": "/api/{$kebab}/{id}", "handler": "{$controller}@show" }
            ],

            "emits": [],
            "listens": [],
            "config": []
        }

        JSON;
    }

    private function provider(string $s, string $ns): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$ns};

        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Contracts\\ModuleContract;
        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Container\\ModuleContainer;
        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Database\\TransactionManager;
        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Events\\{DomainEventCollector, EventBus};
        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Pipelines\\Cli\\CliPipeline;
        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Pipelines\\Http\\HttpPipeline;
        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Pipelines\\Worker\\WorkerPipeline;
        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Ports\\DatabasePort;
        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Security\\Identity;
        use {$ns}\\API\\Contracts\\{$s}ServiceContract;
        use {$ns}\\Application\\Services\\{$s}Service;
        use {$ns}\\Infrastructure\\Persistence\\{$s}Repository;

        final class Provider implements ModuleContract
        {
            public function solves(): string { return '{$this->snake($s)}.management'; }

            /** @return list<class-string> */
            public function requires(): array { return [DatabasePort::class]; }

            /** @return list<class-string> */
            public function exposes(): array { return [{$s}ServiceContract::class]; }

            public function register(ModuleContainer \$container): void
            {
                \$container->bindInternal({$s}Repository::class, static fn(ModuleContainer \$c) =>
                    new {$s}Repository(\$c->make(DatabasePort::class), \$c->make(Identity::class)));

                \$container->bind({$s}ServiceContract::class, static fn(ModuleContainer \$c) =>
                    new {$s}Service(
                        repository:  \$c->make({$s}Repository::class),
                        transaction: \$c->make(TransactionManager::class),
                        collector:   \$c->make(DomainEventCollector::class),
                        eventBus:    \$c->make(EventBus::class),
                        identity:    \$c->make(Identity::class),
                    ));
            }

            public function boot(HttpPipeline \$http, CliPipeline \$cli, WorkerPipeline \$worker, EventBus \$events): void
            {
            }
        }

        PHP;
    }

    private function contract(string $s, string $ns): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$ns}\\API\\Contracts;

        interface {$s}ServiceContract
        {
            /** @return array<int,array<string,mixed>> */
            public function all(): array;

            /** @return array<string,mixed> */
            public function find(string \$id): array;
        }

        PHP;
    }

    private function service(string $s, string $ns): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$ns}\\Application\\Services;

        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Database\\TransactionManager;
        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Events\\{DomainEventCollector, EventBus};
        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Security\\Identity;
        use {$ns}\\API\\Contracts\\{$s}ServiceContract;
        use {$ns}\\Infrastructure\\Persistence\\{$s}Repository;

        final class {$s}Service implements {$s}ServiceContract
        {
            public function __construct(
                private readonly {$s}Repository    \$repository,
                private readonly TransactionManager \$transaction,
                private readonly DomainEventCollector \$collector,
                private readonly EventBus           \$eventBus,
                private readonly Identity           \$identity,
            ) {}

            /** @return array<int,array<string,mixed>> */
            public function all(): array
            {
                return \$this->repository->all();
            }

            /** @return array<string,mixed> */
            public function find(string \$id): array
            {
                return \$this->repository->find(\$id);
            }
        }

        PHP;
    }

    private function entity(string $s, string $ns): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$ns}\\Domain\\Entities;

        final class {$s}
        {
            /** @var array<int,object> */
            private array \$domainEvents = [];

            private function __construct(
                private readonly string \$id,
            ) {}

            public static function create(string \$id): self
            {
                return new self(\$id);
            }

            public static function reconstitute(string \$id): self
            {
                return new self(\$id);
            }

            public function id(): string { return \$this->id; }

            /** @return array<int,object> */
            public function releaseEvents(): array
            {
                \$events = \$this->domainEvents;
                \$this->domainEvents = [];
                return \$events;
            }
        }

        PHP;
    }

    private function repository(string $s, string $ns): string
    {
        $table = $this->snake($s) . 's';
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$ns}\\Infrastructure\\Persistence;

        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Ports\\DatabasePort;
        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Security\\Identity;
        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Exceptions\\RepositoryException;

        final class {$s}Repository
        {
            public function __construct(
                private readonly DatabasePort \$db,
                private readonly Identity     \$identity,
            ) {}

            /** @return array<int,array<string,mixed>> */
            public function all(): array
            {
                try {
                    return \$this->db->query(
                        'SELECT * FROM {$table} WHERE tenant_id = :tenant',
                        ['tenant' => \$this->identity->tenantId]
                    );
                } catch (\\PDOException \$e) {
                    throw new RepositoryException('Failed to list {$table}', layer: 'repository.{$this->snake($s)}', previous: \$e);
                }
            }

            /** @return array<string,mixed> */
            public function find(string \$id): array
            {
                try {
                    \$row = \$this->db->queryOne(
                        'SELECT * FROM {$table} WHERE id = :id AND tenant_id = :tenant',
                        ['id' => \$id, 'tenant' => \$this->identity->tenantId]
                    );
                } catch (\\PDOException \$e) {
                    throw new RepositoryException("Failed to find {$this->snake($s)} [\$id]", layer: 'repository.{$this->snake($s)}', previous: \$e);
                }

                if (\$row === null) {
                    throw new RepositoryException("{$s} [\$id] not found", layer: 'repository.{$this->snake($s)}');
                }

                return \$row;
            }
        }

        PHP;
    }

    private function controller(string $s, string $ns): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$ns}\\Infrastructure\\Http;

        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Http\\Request;
        use AlfacodeTeam\\PhpServicePlatform\\Kernel\\Http\\Response;
        use {$ns}\\API\\Contracts\\{$s}ServiceContract;

        final class {$s}Controller
        {
            public function __construct(
                private readonly {$s}ServiceContract \$service,
            ) {}

            public function index(Request \$request): Response
            {
                return Response::json(\$this->service->all());
            }

            public function show(Request \$request, string \$id): Response
            {
                return Response::json(\$this->service->find(\$id));
            }
        }

        PHP;
    }
}
