import { useEffect, useState } from "react";
import { Head, Link, router } from "@pageflow/react";
import { Button } from "@ui/button";
import { Card, CardContent } from "@ui/card";
import { Alert, AlertDescription } from "@ui/alert";
import { Skeleton } from "@ui/skeleton";
import { StatusBadge, TenantBadge, useTenancy, type TenantSummary } from "@tenancy";

// SITE page contributed by the Tenancy PLUGIN → component "Tenant/Index".
// Server: TenantPageController@index. It hydrates over GET /ajx/me/tenants and
// re-mints a tenant-scoped token via POST /ajx/tenants/{id}/select.
export default function TenantIndex() {
  const api = useTenancy();
  const [tenants, setTenants] = useState<TenantSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [selecting, setSelecting] = useState<string | null>(null);

  useEffect(() => {
    api
      .myTenants()
      .then((rows) => setTenants(rows ?? []))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  async function select(id: string) {
    setSelecting(id);
    setError(null);
    try {
      await api.selectTenant(id);
      // A fresh tenant-scoped session cookie is now set; reload into the tenant.
      router.reload();
    } catch (e) {
      setError((e as Error).message);
      setSelecting(null);
    }
  }

  return (
    <>
      <Head title="Your tenants" />
      <main className="mx-auto max-w-3xl p-8">
        <header className="mb-6 flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-semibold">Your tenants</h1>
            <p className="text-sm text-muted-foreground">Pick a workspace to continue.</p>
          </div>
          <Button variant="link" asChild>
            <Link href="/tenant/hosts">Manage hosts →</Link>
          </Button>
        </header>

        {error && (
          <Alert variant="destructive" className="mb-4">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        {loading ? (
          <div className="space-y-3">
            <Skeleton className="h-20 w-full" />
            <Skeleton className="h-20 w-full" />
          </div>
        ) : tenants.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            You are not a member of any tenant yet. Accept an invitation to get started.
          </p>
        ) : (
          <div className="space-y-3">
            {tenants.map((t) => (
              <Card key={t.tenantId}>
                <CardContent className="flex items-center justify-between p-4">
                  <div className="flex items-center gap-4">
                    <TenantBadge tenant={t} />
                    <span className="text-xs text-muted-foreground">{t.role}</span>
                    <StatusBadge status={t.status} />
                  </div>
                  <Button size="sm" disabled={selecting !== null} onClick={() => select(t.tenantId)}>
                    {selecting === t.tenantId ? "Switching…" : "Open"}
                  </Button>
                </CardContent>
              </Card>
            ))}
          </div>
        )}
      </main>
    </>
  );
}
