// Public barrel for the User plugin's UI — reachable from any surface as
// `@user` / `@user/components/...` once `hkm ui sync` has federated it.
// The plugin exposes shared building blocks here; its PAGES live under
// admin/Pages (admin surface) and site/Pages (public surface).
export { UserBadge } from "./components/UserBadge";
export type { UserSummary } from "./components/UserBadge";
