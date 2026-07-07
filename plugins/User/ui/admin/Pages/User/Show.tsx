import { usePage, useForm, Head, Link } from "@pageflow/react";
import { Button } from "@ui/button";
import { Card, CardContent, CardHeader } from "@ui/card";
import { UserBadge, type UserSummary } from "@user";

type ShowProps = { user: (UserSummary & { createdAt: string }) | null };

export default function UserShow() {
  const { props } = usePage<ShowProps>();
  const form = useForm({});

  if (!props.user) {
    return (
      <main className="mx-auto max-w-2xl p-8">
        <p>User not found.</p>
        <Button variant="link" className="px-0" asChild>
          <Link href="/admin/users">← Back to users</Link>
        </Button>
      </main>
    );
  }

  const u = props.user;
  function verify() {
    form.post(`/ajx/users/${u.id}/verify-email`, { preserveScroll: true });
  }

  return (
    <>
      <Head title={`${u.username} — Admin`} />
      <main className="mx-auto max-w-2xl p-8">
        <Button variant="link" className="px-0" asChild>
          <Link href="/admin/users">← Back to users</Link>
        </Button>
        <Card className="mt-4">
          <CardHeader className="flex flex-row items-center justify-between space-y-0">
            <UserBadge user={u} />
            {!u.emailVerified && (
              <Button size="sm" onClick={verify} disabled={form.processing}>
                {form.processing ? "…" : "Mark verified"}
              </Button>
            )}
          </CardHeader>
          <CardContent>
            <dl className="grid grid-cols-3 gap-2 text-sm">
              <dt className="text-muted-foreground">ID</dt>
              <dd className="col-span-2">{u.id}</dd>
              <dt className="text-muted-foreground">Email</dt>
              <dd className="col-span-2">{u.email}</dd>
              <dt className="text-muted-foreground">Joined</dt>
              <dd className="col-span-2">{new Date(u.createdAt).toLocaleString()}</dd>
            </dl>
          </CardContent>
        </Card>
      </main>
    </>
  );
}
