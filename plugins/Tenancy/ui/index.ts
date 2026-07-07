// Public barrel for the Tenancy plugin's UI — reachable from any surface as
// `@tenancy` / `@tenancy/lib/client` once `hkm ui sync` has federated it.
// The plugin exposes shared building blocks + a typed API client here; its PAGES
// live under admin/Pages (platform-admin surface) and site/Pages (tenant surface).
export { TenantBadge } from "./components/TenantBadge";
export { StatusBadge } from "./components/StatusBadge";
export { useTenancy, tenancyClient } from "./lib/client";
export type {
  TenantSummary,
  TenantDetail,
  TenantHost,
  HostInstructions,
  HostVerification,
} from "./lib/client";
