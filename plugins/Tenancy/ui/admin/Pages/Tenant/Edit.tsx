import { useEffect, useState } from "react";
import { usePage, Head, Link, router } from "@pageflow/react";
import { Button } from "@ui/button";
import { Input } from "@ui/input";
import { Label } from "@ui/label";
import { Card, CardContent } from "@ui/card";
import { Alert, AlertDescription } from "@ui/alert";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@ui/select";
import { useTenancy, type TenantDetail } from "@tenancy";

// ADMIN page contributed by the Tenancy PLUGIN → component "Tenant/Edit".
// Server: TenantPageController@edit passes { tenantId }. Only name/slug/status
// are editable (PUT /ajx/admin/tenants/{id}); DB connection details are fixed at
// provisioning time. Platform-admin only.
type EditProps = { tenantId: string };

const STATUSES = ["active", "provisioning", "suspended", "deleted"];

export default function TenantEdit() {
  const { props } = usePage<EditProps>();
  const { tenantId } = props;
  const api = useTenancy();

  const [tenant, setTenant] = useState<TenantDetail | null>(null);
  const [name, setName] = useState("");
  const [slug, setSlug] = useState("");
  const [status, setStatus] = useState("active");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api
      .adminTenant(tenantId)
      .then((t) => {
        setTenant(t);
        setName(t.name);
        setSlug(t.slug);
        setStatus(t.status);
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [tenantId]);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    setErrors({});
    try {
      await api.adminUpdateTenant(tenantId, { name: name.trim(), slug: slug.trim(), status });
      router.visit("/tenants/manage");
    } catch (e) {
      const err = e as { message: string; fields?: Record<string, string> };
      setErrors(err.fields ?? {});
      setError(err.message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <>
      <Head title="Edit tenant — Admin" />
      <main className="mx-auto max-w-xl p-8">
        <Button variant="link" className="px-0" asChild>
          <Link href="/tenants/manage">← Back to tenants</Link>
        </Button>
        <h1 className="mb-1 mt-2 text-2xl font-semibold">Edit tenant</h1>
        <p className="mb-6 text-sm text-muted-foreground">
          Only the name, slug and status can be changed. Database connection details are fixed.
        </p>

        {error && (
          <Alert variant="destructive" className="mb-4">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        {loading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : (
          <Card>
            <CardContent className="pt-6">
              <form onSubmit={submit} className="space-y-4">
                <div className="space-y-1.5">
                  <Label htmlFor="name">Name</Label>
                  <Input id="name" value={name} onChange={(e) => setName(e.target.value)} />
                  {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="slug">Slug</Label>
                  <Input id="slug" value={slug} onChange={(e) => setSlug(e.target.value)} />
                  {errors.slug && <p className="text-sm text-destructive">{errors.slug}</p>}
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="status">Status</Label>
                  <Select value={status} onValueChange={setStatus}>
                    <SelectTrigger id="status">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {STATUSES.map((s) => (
                        <SelectItem key={s} value={s}>
                          {s}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {errors.status && <p className="text-sm text-destructive">{errors.status}</p>}
                </div>

                {tenant && (
                  <dl className="grid grid-cols-3 gap-2 rounded-md border border-border p-4 text-sm text-muted-foreground">
                    <dt>Database</dt>
                    <dd className="col-span-2">
                      {tenant.dbDriver} · {tenant.dbName} @ {tenant.dbHost}:{tenant.dbPort}
                    </dd>
                    <dt>Schema</dt>
                    <dd className="col-span-2">{tenant.schemaVersion ?? "—"}</dd>
                  </dl>
                )}

                <div className="flex gap-3 pt-2">
                  <Button type="submit" disabled={saving}>
                    {saving ? "Saving…" : "Save changes"}
                  </Button>
                  <Button type="button" variant="outline" asChild>
                    <Link href="/tenants/manage">Cancel</Link>
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>
        )}
      </main>
    </>
  );
}
