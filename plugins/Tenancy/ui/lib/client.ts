// Typed API client for the Tenancy plugin's /ajx endpoints — a TypeScript port
// of the vanilla `TenancyApp` client that the plugin's server views used.
//
// Same-site auth: the browser sends the session cookie automatically
// (credentials:"same-origin"), so there is no bearer token. Every UNSAFE request
// (POST/PUT/PATCH/DELETE) carries the CSRF token — read from the <meta> tag that
// Pageflow embeds in the HTML shell — in the X-CSRF-Token header, so the kernel
// SecurityGateway accepts the mutation.

const API_BASE = "/ajx";
const SAFE: Record<string, 1> = { GET: 1, HEAD: 1, OPTIONS: 1 };

export interface TenantSummary {
  tenantId: string;
  name: string;
  slug: string;
  role: string;
  status: string;
}

export interface TenantDetail {
  tenantId: string;
  name: string;
  slug: string;
  dbDriver: string;
  dbHost: string;
  dbPort: number;
  dbName: string;
  dbUsername: string;
  status: string;
  schemaVersion: string | null;
}

export interface TenantHost {
  host_id: number;
  tenant_id: string;
  hostname: string;
  ip_address: string | null;
  status: string;
  verification_token: string;
  is_primary: boolean;
  verified_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface HostInstructions {
  hostname: string;
  dns_record: { type: string; name: string; value: string; ttl: number };
  expected_ip: string | null;
  instructions: string;
}

export interface HostVerification {
  hostname: string;
  verified: boolean;
  status: string;
  reason: string | null;
  found: { txt: string[]; ips: string[] };
}

/** An API error that carries the HTTP status + per-field validation messages. */
export class TenancyApiError extends Error {
  status: number;
  fields: Record<string, string>;
  constructor(message: string, status: number, fields: Record<string, string> = {}) {
    super(message);
    this.name = "TenancyApiError";
    this.status = status;
    this.fields = fields;
  }
}

function csrfToken(): string {
  const m = document.querySelector('meta[name="csrf-token"]');
  return m?.getAttribute("content") ?? "";
}

function buildHeaders(method: string, hasBody: boolean): HeadersInit {
  const h: Record<string, string> = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  };
  if (hasBody) h["Content-Type"] = "application/json";
  if (!SAFE[method]) h["X-CSRF-Token"] = csrfToken();
  return h;
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  const res = await fetch(API_BASE + path, {
    method,
    headers: buildHeaders(method, body !== undefined),
    body: body !== undefined ? JSON.stringify(body) : undefined,
    credentials: "same-origin",
  });

  const text = await res.text();
  const parsed = text ? JSON.parse(text) : null;

  if (!res.ok) {
    const err = parsed?.error ?? {};
    throw new TenancyApiError(err.message ?? `HTTP ${res.status}`, res.status, err.fields ?? {});
  }

  // Endpoints wrap payloads in a { data: … } envelope. Unwrap it here so callers
  // work with the value directly; tolerant when a bare value is returned.
  return (parsed && typeof parsed === "object" && "data" in parsed ? parsed.data : parsed) as T;
}

export const tenancyClient = {
  // Tenant picker (any authenticated user).
  myTenants: () => request<TenantSummary[]>("GET", "/me/tenants"),
  selectTenant: (id: string) =>
    request<{ token: string; tokenType: string; tenantId: string; role: string; expiresIn: number }>(
      "POST",
      `/tenants/${encodeURIComponent(id)}/select`,
    ),

  // Tenant administration (platform-admin only).
  adminTenants: () => request<TenantDetail[]>("GET", "/admin/tenants"),
  adminTenant: (id: string) => request<TenantDetail>("GET", `/admin/tenants/${encodeURIComponent(id)}`),
  adminCreateTenant: (payload: Record<string, unknown>) =>
    request<TenantDetail>("POST", "/admin/tenants", payload),
  adminUpdateTenant: (id: string, payload: Record<string, unknown>) =>
    request<TenantDetail>("PUT", `/admin/tenants/${encodeURIComponent(id)}`, payload),
  adminDeleteTenant: (id: string, dropDatabase: boolean) =>
    request<null>("DELETE", `/admin/tenants/${encodeURIComponent(id)}`, { drop_database: dropDatabase }),

  // Invitations.
  acceptInvitation: (token: string) => request<{ tenantId: string }>("POST", "/invitations/accept", { token }),

  // Custom hosts for the currently-scoped tenant.
  hosts: () => request<TenantHost[]>("GET", "/tenant/hosts"),
  addHost: (payload: { hostname: string; ip_address?: string | null }) =>
    request<HostInstructions>("POST", "/tenant/hosts", payload),
  hostInstructions: (id: number) =>
    request<HostInstructions>("GET", `/tenant/hosts/${id}/instructions`),
  verifyHost: (id: number) => request<HostVerification>("POST", `/tenant/hosts/${id}/verify`),
  makeHostPrimary: (id: number) => request<TenantHost>("POST", `/tenant/hosts/${id}/primary`),
  removeHost: (id: number) => request<null>("DELETE", `/tenant/hosts/${id}`),
};

/** Hook alias so pages can `const api = useTenancy()`. The client is stateless. */
export function useTenancy() {
  return tenancyClient;
}
