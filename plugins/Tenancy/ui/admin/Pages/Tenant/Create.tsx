import { useState } from "react";
import { Head, Link, router } from "@pageflow/react";
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
import { useTenancy } from "@tenancy";

// ADMIN page contributed by the Tenancy PLUGIN → component "Tenant/Create".
// Server: TenantPageController@create. Provisions a new tenant (registry row +
// isolated database) via POST /ajx/admin/tenants. Platform-admin only.
type Form = {
  name: string;
  slug: string;
  driver: string;
  db_name: string;
  db_user: string;
  db_password: string;
  db_host: string;
  db_port: string;
};

const DRIVERS = ["mysql", "pgsql", "sqlsrv"];

export default function TenantCreate() {
  const api = useTenancy();
  const [form, setForm] = useState<Form>({
    name: "",
    slug: "",
    driver: "mysql",
    db_name: "",
    db_user: "",
    db_password: "",
    db_host: "127.0.0.1",
    db_port: "",
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const set = (key: keyof Form) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm((f) => ({ ...f, [key]: e.target.value }));

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    setErrors({});
    try {
      await api.adminCreateTenant({
        name: form.name.trim(),
        slug: form.slug.trim(),
        driver: form.driver.trim(),
        db_name: form.db_name.trim(),
        db_user: form.db_user.trim(),
        db_password: form.db_password,
        db_host: form.db_host.trim(),
        db_port: form.db_port ? parseInt(form.db_port, 10) : 0,
      });
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
      <Head title="New tenant — Admin" />
      <main className="mx-auto max-w-xl p-8">
        <Button variant="link" className="px-0" asChild>
          <Link href="/tenants/manage">← Back to tenants</Link>
        </Button>
        <h1 className="mb-1 mt-2 text-2xl font-semibold">New tenant</h1>
        <p className="mb-6 text-sm text-muted-foreground">
          Creates the registry row and provisions an isolated database.
        </p>

        {error && (
          <Alert variant="destructive" className="mb-4">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        <Card>
          <CardContent className="pt-6">
            <form onSubmit={submit} className="space-y-4">
              <Field label="Name" htmlFor="name" error={errors.name}>
                <Input id="name" value={form.name} onChange={set("name")} placeholder="Acme Inc" />
              </Field>
              <Field label="Slug" htmlFor="slug" error={errors.slug}>
                <Input id="slug" value={form.slug} onChange={set("slug")} placeholder="acme" />
              </Field>
              <Field label="Database driver" htmlFor="driver" error={errors.driver}>
                <Select value={form.driver} onValueChange={(v) => setForm((f) => ({ ...f, driver: v }))}>
                  <SelectTrigger id="driver">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {DRIVERS.map((d) => (
                      <SelectItem key={d} value={d}>
                        {d}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
              <div className="grid grid-cols-2 gap-4">
                <Field label="Database name" htmlFor="db_name" error={errors.db_name}>
                  <Input id="db_name" value={form.db_name} onChange={set("db_name")} placeholder="tnt_acme" />
                </Field>
                <Field label="Database user" htmlFor="db_user" error={errors.db_user}>
                  <Input id="db_user" value={form.db_user} onChange={set("db_user")} placeholder="acme_user" />
                </Field>
              </div>
              <Field label="Database password" htmlFor="db_password" error={errors.db_password}>
                <Input
                  id="db_password"
                  type="password"
                  value={form.db_password}
                  onChange={set("db_password")}
                  autoComplete="new-password"
                />
              </Field>
              <div className="grid grid-cols-2 gap-4">
                <Field label="Database host" htmlFor="db_host" error={errors.db_host}>
                  <Input id="db_host" value={form.db_host} onChange={set("db_host")} />
                </Field>
                <Field label="Database port" htmlFor="db_port" error={errors.db_port}>
                  <Input
                    id="db_port"
                    type="number"
                    min={1}
                    max={65535}
                    value={form.db_port}
                    onChange={set("db_port")}
                    placeholder="3306"
                  />
                </Field>
              </div>

              <div className="flex gap-3 pt-2">
                <Button type="submit" disabled={saving}>
                  {saving ? "Provisioning…" : "Create tenant"}
                </Button>
                <Button type="button" variant="outline" asChild>
                  <Link href="/tenants/manage">Cancel</Link>
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      </main>
    </>
  );
}

function Field({
  label,
  htmlFor,
  error,
  children,
}: {
  label: string;
  htmlFor: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={htmlFor}>{label}</Label>
      {children}
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  );
}
