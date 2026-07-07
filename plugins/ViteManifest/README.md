# ViteManifest (`Plugins\ViteManifest`, solves `vite.manifest`)

Resolves Vite build assets for HTML routes — hashed `<script>`/`<link>` tags,
HMR dev-server injection, module/style preloading, SRI integrity, CSP nonce,
and the React Fast Refresh preamble.

It is the GDA-clean rewrite of the old Laravel `Vite` transplant: **no framework
globals** (`public_path()`, `asset()`, `app()`, `collect()` and the `Illuminate`
`Collection`/`HtmlString` are gone), all config is injected, and it targets the
**surface** output of the `hkmPlugin` vite plugin
(`tools/src/templates/frontend`):

```
app/public/<surface>-hot                          ← dev server URL (HMR)
app/public/build/manifest-<surface>.json          ← prod manifest
app/public/build/<surface>/<name>.<hash>.js        ← hashed assets
```

## Layout

| File | Role |
|---|---|
| `API/Contracts/ViteContract.php` | published interface (inject into controllers) |
| `Infrastructure/Vite.php` | the resolver (render / asset / reactRefresh / preload) |
| `Infrastructure/ManifestReader.php` | manifest load + process-static cache |
| `ViteConfig.php` | immutable config value object (`withSurface`, `withNonce`) |
| `ViteFactory.php` | builds a `Vite` from env + `Paths` |
| `Support/Html.php` | Stringable safe-HTML wrapper (replaces `HtmlString`) |
| `Support/helpers.php` | `vite()` / `vite_asset()` / `vite_react_refresh()` for views |

## Activation

On-demand. A route rendering a shell that needs built assets declares:

```json
{ "requires": ["vite.manifest"] }
```

Enable in a project: `hkm plugins enable vitemanifest` (wires the helper require).

## Usage — in a view

The manifest key is the entry path **relative to the vite root** (`frontend/`):

```php
<!doctype html>
<html>
<head>
  <?= vite_react_refresh('admin') ?>          <!-- dev only, no-op in prod -->
  <?= vite('src/surfaces/admin/index.tsx', 'admin') ?>
</head>
<body><div id="app" data-page="<?= $page ?>"></div></body>
</html>
```

- **Dev** (an `app/public/admin-hot` file exists → the vite dev server is up):
  emits `@vite/client` + the entry from the dev-server URL.
- **Prod**: reads `manifest-admin.json`, emits preloads + hashed `<link>`/`<script>`.

## Usage — in a controller

```php
public function __construct(private readonly ViteContract $vite) {}

public function show(): Response
{
    $tags = $this->vite->forSurface('admin')->render('src/surfaces/admin/index.tsx');
    // … pass $tags into your view / Response::html(...)
}
```

## Config (`module.json` `config[]`, all optional)

| Env | Default | Meaning |
|---|---|---|
| `VITE_PUBLIC_PATH` | `<project>/app/public` | web root serving built assets |
| `VITE_BUILD_DIRECTORY` | `build` | build sub-dir |
| `VITE_SURFACE` | *(none)* | default surface when a call omits it |
| `VITE_MANIFEST` | `manifest.json` | manifest name when no surface |
| `VITE_HOT_PATH` | *(derived)* | explicit hot-file path override |
| `ASSET_URL` | `""` | CDN/base URL prefix for built assets |
| `VITE_INTEGRITY_KEY` | `integrity` | manifest SRI key; `false` disables |
| `VITE_NONCE` | *(none)* | CSP nonce applied to every tag |
