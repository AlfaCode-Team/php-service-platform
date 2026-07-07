import { useEffect, useState } from "react";
import { Head, Link } from "@pageflow/react";
import { Button } from "@ui/button";
import { Alert, AlertDescription } from "@ui/alert";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@ui/table";
import { StatusBadge, useTenancy, type TenantDetail } from "@tenancy";

// ADMIN page contributed by the Tenancy PLUGIN → component "Tenant/Manage".
// Server: TenantPageController@manage. Platform-admin control plane for the whole
// fleet, backed by GET/DELETE /ajx/admin/tenants (requires platform-admin access).
export default function TenantManage() {
  const api = useTenancy();
  const [tenants, setTenants] = useState<TenantDetail[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      setTenants((await api.adminTenants()) ?? []);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
  }, []);

  async function remove(t: TenantDetail) {
    if (!window.confirm(`Delete tenant "${t.name}"? This drops its database user and registry row.`)) {
      return;
    }
    const dropDatabase = window.confirm(
      `Also DROP the tenant database "${t.dbName}"? All its data is lost. OK = drop, Cancel = keep.`,
    );
    try {
      await api.adminDeleteTenant(t.tenantId, dropDatabase);
      await load();
    } catch (e) {
      setError((e as Error).message);
    }
  }

  return (
    <>
      <Head title="Manage tenants — Admin" />
      <main className="mx-auto max-w-4xl p-8">
        <header className="mb-6 flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-semibold">Tenants</h1>
            <p className="text-sm text-muted-foreground">
              Control plane for the whole fleet. Requires platform-admin access.
            </p>
          </div>
          <Button asChild>
            <Link href="/tenants/create">+ New tenant</Link>
          </Button>
        </header>

        {error && (
          <Alert variant="destructive" className="mb-4">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        {loading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : tenants.length === 0 ? (
          <p className="text-sm text-muted-foreground">No tenants yet. Create the first one.</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Name</TableHead>
                <TableHead>Slug</TableHead>
                <TableHead>Database</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {tenants.map((t) => (
                <TableRow key={t.tenantId}>
                  <TableCell className="font-medium">{t.name}</TableCell>
                  <TableCell className="text-muted-foreground">{t.slug}</TableCell>
                  <TableCell className="text-muted-foreground">
                    {t.dbDriver} · {t.dbName} @ {t.dbHost}:{t.dbPort}
                  </TableCell>
                  <TableCell>
                    <StatusBadge status={t.status} />
                  </TableCell>
                  <TableCell className="space-x-1 text-right">
                    <Button variant="ghost" size="sm" asChild>
                      <Link href={`/tenants/${t.tenantId}/edit`}>Edit</Link>
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => remove(t)}>
                      Delete
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </main>
    </>
  );
}
