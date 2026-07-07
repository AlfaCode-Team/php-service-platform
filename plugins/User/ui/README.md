# User plugin UI — admin + frontend pages

The User plugin ships a Pageflow (React) UI with pages for **both faces**:

```
plugins/User/ui/
├─ ui.json                 alias "@user" + surfaces map { admin: admin/Pages, site: site/Pages }
├─ index.ts                barrel — exposes shared bits as @user
├─ components/UserBadge.tsx a shared component (reused by the pages)
├─ admin/Pages/User/        ADMIN surface pages
│   ├─ Index.tsx            component "User/Index"  (users list)
│   └─ Show.tsx             component "User/Show"   (user detail)
└─ site/Pages/User/         PUBLIC surface pages
    ├─ Register.tsx         component "User/Register" (public signup)
    └─ Profile.tsx          component "User/Profile"  (my account)
```

## How it reaches a project

1. **Federation** — `hkm ui sync` mirrors this `ui/` into the project's
   `frontend/plugins/user/` and adds the `@user` alias to `tsconfig.plugins.json`.
2. **Per-face discovery** — each surface entry globs the plugin pages for its
   face, so no per-page wiring:
   - admin surface: `import.meta.glob("../../../plugins/*/admin/Pages/**/*.tsx")`
   - public surface: `import.meta.glob("../../../plugins/*/site/Pages/**/*.tsx")`
   Project pages are spread first, so a project can override a plugin page.
3. **Server** — the plugin's `UserFlowController` renders the component names:
   `render($request, 'User/Index'|'User/Show'|'User/Register'|'User/Profile', …)`.
   Routes live in `module.json` with `requires: ["http.pageflow","user.management"]`.

## Routes (module.json)

| Method · Path | Face | Component | Filter |
|---|---|---|---|
| GET `/admin/users` | admin | `User/Index` | `auth` |
| GET `/admin/users/{id}` | admin | `User/Show` | `auth` |
| GET `/register` | site | `User/Register` | — |
| GET `/account/profile` | site | `User/Profile` | `auth` |

`/admin/*` routes render through the **admin** surface; the rest through the
public surface. A single Pageflow shell can pick the surface by URL face (see the
psp-shop `resources/layouts/pageflow.php` — `str_starts_with($FLOW_PAGE->url, '/admin')`).

## Authorization

`UserService::list()` enforces the `user:list` permission (admin-only). Session
login sets no permissions by default — grant them from assigned roles at login
(`AuthService::startSession($session, $id, $roles, $permissions)`), or, for a
demo, via a project stage that injects permissions for authenticated users (see
psp-shop `GrantDemoAdminStage` + `DEMO_ADMIN_PERMISSIONS`). Note: services read
Identity from the request-scoped container (bound at LoadStage, before session
auth), so a stage that elevates permissions must **rebind** `Identity::class`
into `$request->container()`, not just the request.
