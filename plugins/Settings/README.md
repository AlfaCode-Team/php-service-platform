# Settings plugin

Per-tenant settings (`tenant.settings`). Reads/writes the central
`tenant_settings_*` tables, keyed by `tenant_id`, exposing
`SettingsServiceContract` and a JSON API under `/api/settings/*`.

The acting tenant always comes from the authenticated `Identity` (the signed
`tnt` claim) — never from client input. An unscoped (central) token is rejected
with `403`.

## Read / write semantics

- **`GET /api/settings/{section}`** returns the tenant's stored row, or a fully
  populated DTO of hard-coded defaults (`DTO::defaults()`) when none exists —
  callers never see nulls for defaulted columns.
- **`PUT /api/settings/{section}`** is a **partial update**: the payload is
  validated, then merged over the tenant's *current* stored settings, so an
  omitted field keeps its existing value (it is never reset to a default). Input
  is validated via `Plugins\Validation\Validator` → `422` with field errors.
- **Writes are authorized**: the caller needs the `settings:manage` permission or
  an `admin`/`super` role, else `ServiceException` (unauthorized). Reads are open
  to any authenticated tenant member.

## Sections

| Section          | Table                              | DTO                         |
|------------------|------------------------------------|-----------------------------|
| `company`        | `tenant_settings_company`          | `CompanySettingsDTO`        |
| `contact`        | `tenant_settings_contact`          | `ContactSettingsDTO`        |
| `email`          | `tenant_settings_email`            | `EmailSettingsDTO`          |
| `email_providers`| `tenant_settings_email_providers`  | `EmailProviderSettingsDTO`  |
| `system`         | `tenant_settings_system`           | `SystemSettingsDTO`         |

`Domain/ValueObjects/SettingsSection` is the single source of truth for the
section→table mapping; no caller-supplied string is ever interpolated into SQL.

## Company logo

The company logo is a stored blob, not a path the client controls. Two routes
(both `requires: ["storage.local"]`) manage it via `StoragePort`:

- **`POST /api/settings/company/logo`** — multipart field `company_logo`.
  Validates the extension (`png, jpg, jpeg, gif, webp, svg, ico`), stores the
  blob under `tenants/{tenantId}/branding/` with a random name, persists its path
  onto `company_logo`, deletes the previous blob, and returns the settings plus a
  signed `logo_url`. A failed authorization/persist deletes the just-stored blob
  (no orphans).
- **`DELETE /api/settings/company/logo`** — clears `company_logo` and deletes the
  stored blob.

Unlike the legacy controller, nothing is written to `public_path()` and there is
no favicon-set side effect — a single blob per tenant, served via a signed URL.

## Migrations

The schema lives in `database/migrations/` and is owned by this plugin. These are
central control-plane tables (`char(31)` `tenant_id` PK) with a foreign key to the
Tenancy `tenants` table, so they run on the **central** connection and require
Tenancy's `tenants` table to exist first (earlier migration timestamps guarantee
that ordering).

This framework does **not** declare migration directories in `module.json`.
Register this plugin's path in the project's `config/let-migrate.php` `paths[]`:

```php
'paths' => [
    $root . '/database/migrations',
    $root . '/plugins/Tenancy/database/migrations',  // tenants (FK target) — first
    $root . '/plugins/Settings/database/migrations',
],
```

Then `php cli/run.php migrate:run` applies them in timestamp order across all paths.

## Wiring

Register the provider in the project bootstrap, then opt routes in on demand:

```php
->withModules([ /* … */ \Plugins\Settings\Provider::class ])
```

A route uses it via `"requires": ["tenant.settings"]`.
