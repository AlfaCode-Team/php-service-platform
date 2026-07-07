# Pageflow

The SPA bridge for the AlfacodeTeam PhpServicePlatform — a fork of **Inertia.js
v2**, rebranded and wired into the kernel, plus platform-native capabilities
Inertia doesn't have (secure realtime, native validation/precognition,
permission-aware UI, offline, end-to-end types).

Write normal server controllers that return a **component name + props**; the
client swaps React components in place — SPA feel, no REST API, no client router.

- **Solves:** `http.pageflow`
- **PHP:** `plugins/Pageflow/`
- **Client:** `plugins/Pageflow/ui/` (`@pageflow/core`, `@pageflow/react`)
- **Full usage guide (PDF):** [`ui/PAGEFLOW_USAGE.pdf`](ui/PAGEFLOW_USAGE.pdf)
- **Architecture/flow guide (PDF):** [`ui/PAGEFLOW_GUIDE.pdf`](ui/PAGEFLOW_GUIDE.pdf)

## Quick start

**1. Env** (`.env`):

```
PAGEFLOW_VERSION="1"
PAGEFLOW_ROOT_VIEW="/abs/path/plugins/Pageflow/resources/views/app.php"
PAGEFLOW_APP_ID="app"
PAGEFLOW_CSRF_COOKIE="hkm_session"   # must be in Cookie's encrypt_exempt
```

**2. Register** the plugin in your project `bootstrap/app.php`
(`Plugins\Pageflow\Provider::class`).

**3. Controller** — inject `PageflowResponder`, return `render()`:

```php
final class UserController
{
    public function __construct(
        private readonly PageflowResponder $pageflow,
        private readonly UserServiceContract $users,
    ) {}

    public function index(Request $request): Response
    {
        return $this->pageflow->render($request, 'Users/Index', 'admin', [
            'users' => $this->users->all(),
        ]);
    }
}
```

**4. Client** (`main.tsx`):

```tsx
import { createPageflowApp, resolvePageComponent, installCsrfAutoRefresh } from '@pageflow/react'
import { createRoot } from 'react-dom/client'

installCsrfAutoRefresh()
createPageflowApp({
  resolve: (name) => resolvePageComponent(name, import.meta.glob('./Pages/**/*.tsx')),
  setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
})
```

## Feature map

| Need | Client | Server |
|---|---|---|
| Link between pages | `<Link href>` | route → controller |
| Read controller data | `usePage()` | `render(...)` props |
| Form + validation errors | `useForm()` / `<Form>` | DTO throws `ValidationException` |
| Live validation | `usePrecognition` / `<Form validateOn>` | `pageflow_precognition()` |
| Realtime updates | `useReactiveProps` | `PageflowChannel::touch()` |
| Permission-gated UI | `useAuth()` / `<Can>` | `pageflow_auth` (auto) |
| Set page title | `<Head>` | — |
| Offline | `registerPageflowSW()` | `render(..., cacheable: true)` |
| Typed props | `usePage<T>()` | `hkm pageflow:types` |

## Security invariants (do not regress)

- **Push signals, pull data** — the reactive channel emits prop key *names* only;
  data is always re-fetched through the authenticated pipeline.
- **CSRF token stays same-origin** and lives only in the `<meta>` tag + the
  throttled `/pageflow/csrf` endpoint (never in page-object JSON or SW cache).
- **Client permission checks are UX only** — the Service layer is the authority.
- **Offline caching is opt-in** — authenticated pages are never cached by default.

See the full guide PDFs for cookbook examples, the wire protocol, and the
security hardening ledger.

## Endpoints

| Route | Purpose | Filters |
|---|---|---|
| `GET /pageflow/csrf` | refresh CSRF token | `throttle` |
| `GET /pageflow/stream` | reactive SSE channel (tenant-scoped) | `auth`, `throttle` |

> The SSE stream holds a connection — run it under OpenSwoole, not a small PHP-FPM pool.
