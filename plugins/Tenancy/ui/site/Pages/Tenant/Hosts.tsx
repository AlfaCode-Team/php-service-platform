import { useEffect, useState } from "react";
import { Head, Link } from "@pageflow/react";
import { Button } from "@ui/button";
import { Input } from "@ui/input";
import { Label } from "@ui/label";
import { Badge } from "@ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@ui/card";
import { Alert, AlertDescription } from "@ui/alert";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@ui/table";
import {
  StatusBadge,
  useTenancy,
  type HostInstructions,
  type TenantHost,
} from "@tenancy";

// SITE page contributed by the Tenancy PLUGIN → component "Tenant/Hosts".
// Server: TenantPageController@hosts. Manages the custom domains of the tenant
// the caller is currently scoped to, over the /ajx/tenant/hosts endpoints.
export default function TenantHosts() {
  const api = useTenancy();
  const [hosts, setHosts] = useState<TenantHost[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const [hostname, setHostname] = useState("");
  const [ip, setIp] = useState("");
  const [adding, setAdding] = useState(false);
  const [instructions, setInstructions] = useState<HostInstructions | null>(null);

  async function load() {
    setLoading(true);
    try {
      setHosts((await api.hosts()) ?? []);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
  }, []);

  async function add(e: React.FormEvent) {
    e.preventDefault();
    setAdding(true);
    setError(null);
    setNotice(null);
    try {
      const res = await api.addHost({ hostname: hostname.trim(), ip_address: ip.trim() || null });
      setInstructions(res);
      setHostname("");
      setIp("");
      await load();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setAdding(false);
    }
  }

  async function verify(host: TenantHost) {
    setError(null);
    setNotice(null);
    try {
      const res = await api.verifyHost(host.host_id);
      setNotice(
        res.verified
          ? `${res.hostname} verified.`
          : `${res.hostname} not verified yet${res.reason ? ` — ${res.reason}` : ""}.`,
      );
      await load();
    } catch (e) {
      setError((e as Error).message);
    }
  }

  async function makePrimary(host: TenantHost) {
    try {
      await api.makeHostPrimary(host.host_id);
      await load();
    } catch (e) {
      setError((e as Error).message);
    }
  }

  async function remove(host: TenantHost) {
    if (!window.confirm(`Stop routing ${host.hostname}?`)) return;
    try {
      await api.removeHost(host.host_id);
      await load();
    } catch (e) {
      setError((e as Error).message);
    }
  }

  return (
    <>
      <Head title="Tenant hosts" />
      <main className="mx-auto max-w-3xl p-8">
        <header className="mb-6 flex items-center justify-between">
          <h1 className="text-2xl font-semibold">Custom domains</h1>
          <Button variant="link" asChild>
            <Link href="/tenants">← Your tenants</Link>
          </Button>
        </header>

        {error && (
          <Alert variant="destructive" className="mb-4">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}
        {notice && (
          <Alert className="mb-4">
            <AlertDescription>{notice}</AlertDescription>
          </Alert>
        )}

        <Card className="mb-6">
          <CardHeader>
            <CardTitle className="text-base">Add a domain</CardTitle>
          </CardHeader>
          <CardContent>
            <form onSubmit={add} className="flex flex-wrap items-end gap-3">
              <div className="flex-1 space-y-1.5">
                <Label htmlFor="hostname">Hostname</Label>
                <Input
                  id="hostname"
                  placeholder="app.example.com"
                  value={hostname}
                  onChange={(e) => setHostname(e.target.value)}
                />
              </div>
              <div className="w-40 space-y-1.5">
                <Label htmlFor="ip">IP (optional)</Label>
                <Input
                  id="ip"
                  placeholder="203.0.113.10"
                  value={ip}
                  onChange={(e) => setIp(e.target.value)}
                />
              </div>
              <Button type="submit" disabled={adding || hostname.trim() === ""}>
                {adding ? "Adding…" : "Add host"}
              </Button>
            </form>
          </CardContent>
        </Card>

        {instructions && (
          <Alert className="mb-6">
            <AlertDescription>
              <p className="mb-2 font-medium">Publish this DNS record, then verify:</p>
              <pre className="overflow-auto rounded bg-muted p-3 text-xs">
                {instructions.dns_record.type}  {instructions.dns_record.name}  {instructions.dns_record.value}
              </pre>
              <p className="mt-2">{instructions.instructions}</p>
            </AlertDescription>
          </Alert>
        )}

        {loading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : hosts.length === 0 ? (
          <p className="text-sm text-muted-foreground">No custom domains yet.</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Hostname</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {hosts.map((h) => (
                <TableRow key={h.host_id}>
                  <TableCell>
                    {h.hostname}
                    {h.is_primary && (
                      <Badge variant="outline" className="ml-2 border-transparent bg-indigo-100 text-indigo-700">
                        primary
                      </Badge>
                    )}
                  </TableCell>
                  <TableCell>
                    <StatusBadge status={h.status} />
                  </TableCell>
                  <TableCell className="space-x-1 text-right">
                    <Button variant="ghost" size="sm" onClick={() => verify(h)}>
                      Verify
                    </Button>
                    {!h.is_primary && (
                      <Button variant="ghost" size="sm" onClick={() => makePrimary(h)}>
                        Make primary
                      </Button>
                    )}
                    <Button variant="ghost" size="sm" onClick={() => remove(h)}>
                      Remove
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
