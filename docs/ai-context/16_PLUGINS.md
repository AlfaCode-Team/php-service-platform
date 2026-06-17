# AlfacodeTeam PhpServicePlatform — Plugins Layer Context

> The `plugins/` folder is the home for **locally developed business modules** that belong to
> this specific application but are not published as standalone packages.
> Every module here follows identical GDA rules — only the folder and namespace differ.

---

## Why `plugins/` Exists

| Folder | Purpose |
|---|---|
| `modules/` | First-party framework packages (`bind-it`, `php-io-cli`, etc.) loaded as Composer path repositories. These are git submodules and may be published to Packagist. |
| `projects/` | Project-layer wiring only — bootstrap files, domain resolution, `platform.json`, `projects.json`. No business logic lives here. |
| `plugins/` | Local business modules unique to this application. Full GDA structure. Autoloaded via `Plugins\\` PSR-4 prefix. Never git submodules. |

---

## Namespace and Autoload

```json
// composer.json autoload.psr-4
"Plugins\\": "plugins/"
```

Every plugin's root namespace is `Plugins\{ModuleName}\`.

---

## Plugin Directory Structure

Identical to the standard GDA module layout:

```
plugins/{Name}/
├── module.json                              ← single source of truth
├── Provider.php                             ← implements ModuleContract
├── API/
│   ├── Contracts/{Name}ServiceContract.php
│   ├── Dto/
│   └── IntegrationEvents/
├── Domain/
│   ├── Entities/
│   ├── ValueObjects/
│   ├── Events/
│   └── Rules/
├── Application/
│   └── Services/{Name}Service.php
└── Infrastructure/
    ├── Http/Controllers/{Name}Controller.php
    ├── Persistence/{Name}Repository.php
    └── Gateways/
```

---

## module.json Handler Paths

Because handlers are in the `Plugins\` namespace, use the fully-qualified class string:

```json
{
  "routes": [
    {
      "method": "GET",
      "path": "/api/things",
      "handler": "Plugins\\MyModule\\Infrastructure\\Http\\MyController@index"
    }
  ],
  "exposes": ["Plugins\\MyModule\\Api\\Contracts\\MyServiceContract"]
}
```

---

## Registering a Plugin

Add the `Provider` class to the appropriate project bootstrap:

```php
// projects/admin/bootstrap/app.php
use Plugins\Task\Provider as TaskModule;
use Plugins\MyOtherModule\Provider as MyOtherModule;

return $builder
    ->withModules([
        TaskModule::class,
        MyOtherModule::class,
    ])
    ->build();
```

---

## Registered Plugins

| Plugin | Namespace | Solves | Routes |
|---|---|---|---|
| Task | `Plugins\Task\` | `task.management` | `GET/POST /api/tasks`, `GET/POST/DELETE /api/tasks/{id}` |

Infrastructure plugins (port adapters / pipeline stages, no routes) — see
`20_FIRST_PARTY_PLUGINS.md` for the full list and the MODULE ACTIVATION section
of `CLAUDE.md` for on-demand vs essential:

| Plugin | Solves | Provides | Activation |
|---|---|---|---|
| Storage | `storage.local` | `StoragePort` (local + S3) | on-demand |
| HttpClient | `http.client` | `HttpClientPort` (cURL) | on-demand |
| Session | `session.management` | `SessionPort` | essential |
| Cookie | `http.cookies` | `CookieJar` + flush stage | essential |
| RedisCache | `cache.redis` | `CachePort` + `QueuePort` | essential |
| SecurityFilters | `http.security_filters` | CORS / SecureHeaders / HMAC / auth / rate-limit stages | always-hooked |

---

## Rules

```
✓ plugins/{Name}/  →  Plugins\{Name}\  (PascalCase folder = PascalCase namespace)
✓ module.json handlers use fully-qualified Plugins\... class strings
✓ Provider registered in projects/{project}/bootstrap/app.php
✗ Do NOT place plugin files under projects/ — that folder is for wiring only
✗ Do NOT add plugins as Composer path repositories — Plugins\ PSR-4 covers autoloading
✗ All GDA five-layer access rules apply exactly as for any other module
```

---

## Adding a New Plugin (Checklist)

1. `mkdir -p plugins/{Name}/{API/Contracts,API/Dto,API/IntegrationEvents,Application/Services,Domain/Entities,Domain/ValueObjects,Domain/Events,Infrastructure/Http,Infrastructure/Persistence}`
2. Write `plugins/{Name}/module.json` — set `"type": "module"`, `"solves"`, routes with `Plugins\\{Name}\\...` handlers
3. Implement all layers under `namespace Plugins\{Name}\...`
4. Write `plugins/{Name}/Provider.php` — `namespace Plugins\{Name};` implements `ModuleContract`
5. Add `Plugins\{Name}\Provider::class` to the relevant `projects/*/bootstrap/app.php`
6. Run `composer dump-autoload` if the new namespace isn't picked up automatically
