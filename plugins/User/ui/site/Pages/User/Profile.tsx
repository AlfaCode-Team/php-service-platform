import { usePage, Head, Link } from "@pageflow/react";
import { Button } from "@ui/button";
import { Card, CardContent } from "@ui/card";
import { Alert, AlertDescription } from "@ui/alert";
import { UserBadge, type UserSummary } from "@user";

// PUBLIC "my account" page (component "User/Profile"). Server:
// UserFlowController@profile — reads the authenticated Identity and loads it.
type ProfileProps = { user: (UserSummary & { createdAt: string }) | null };

export default function Profile() {
  const { props } = usePage<ProfileProps>();

  if (!props.user) {
    return (
      <main className="mx-auto max-w-md p-8 text-center">
        <p>You are not signed in.</p>
        <Button variant="link" asChild>
          <Link href="/login">Sign in</Link>
        </Button>
      </main>
    );
  }

  const u = props.user;
  return (
    <>
      <Head title="Your profile" />
      <main className="mx-auto max-w-md p-8">
        <h1 className="mb-6 text-2xl font-semibold">Your profile</h1>
        <Card>
          <CardContent className="pt-6">
            <UserBadge user={u} />
            <dl className="mt-4 space-y-1 text-sm">
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Email</dt>
                <dd>{u.email}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Member since</dt>
                <dd>{new Date(u.createdAt).toLocaleDateString()}</dd>
              </div>
            </dl>
          </CardContent>
        </Card>
        {!u.emailVerified && (
          <Alert className="mt-4">
            <AlertDescription>Please verify your email to unlock all features.</AlertDescription>
          </Alert>
        )}
      </main>
    </>
  );
}
