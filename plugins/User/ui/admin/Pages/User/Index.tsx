import { usePage, Head, Link, router } from "@pageflow/react";
import { Button } from "@ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@ui/table";
import { UserBadge, type UserSummary } from "@user";

// ADMIN page contributed by the User PLUGIN. The admin surface globs
// plugins/*/admin/Pages/**, so this resolves as component "User/Index" even
// though it lives in the plugin. Server: UserFlowController@adminIndex.
type UserRow = UserSummary & { createdAt: string };

type IndexProps = {
  users: UserRow[];
  hasMore: boolean;
  nextCursor: string | null;
};

export default function UserIndex() {
  const { props } = usePage<IndexProps>();

  return (
    <>
      <Head title="Users — Admin" />
      <main className="mx-auto max-w-4xl p-8">
        <header className="mb-6 flex items-center justify-between">
          <h1 className="text-2xl font-semibold">Users</h1>
          <span className="text-sm text-muted-foreground">{props.users.length} shown</span>
        </header>

        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>User</TableHead>
              <TableHead>Email</TableHead>
              <TableHead>Joined</TableHead>
              <TableHead className="text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {props.users.map((u) => (
              <TableRow key={u.id}>
                <TableCell>
                  <UserBadge user={u} />
                </TableCell>
                <TableCell>{u.email}</TableCell>
                <TableCell>{new Date(u.createdAt).toLocaleDateString()}</TableCell>
                <TableCell className="text-right">
                  <Button variant="ghost" size="sm" asChild>
                    <Link href={`/admin/users/${u.id}`}>View</Link>
                  </Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>

        {props.hasMore && props.nextCursor && (
          <div className="mt-6 text-center">
            <Button
              variant="outline"
              onClick={() => router.get("/admin/users", { after: props.nextCursor }, { preserveState: true })}
            >
              Load more
            </Button>
          </div>
        )}
      </main>
    </>
  );
}
