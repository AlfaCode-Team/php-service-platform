// A shared component the plugin EXPOSES to every surface. Consumers import it
// as `@user` (the plugin's federated alias). Built on the project's shared shadcn
// design system (@ui) — so plugin UI and project UI stay visually consistent.
import { Avatar, AvatarFallback } from "@ui/avatar";
import { Badge } from "@ui/badge";

export interface UserSummary {
  id: string;
  username: string;
  email: string;
  emailVerified: boolean;
}

export function UserBadge({ user }: { user: UserSummary }) {
  const initials = user.username.slice(0, 2).toUpperCase();
  return (
    <span className="inline-flex items-center gap-2">
      <Avatar className="h-7 w-7">
        <AvatarFallback className="bg-primary text-xs font-semibold text-primary-foreground">
          {initials}
        </AvatarFallback>
      </Avatar>
      <span className="font-medium">{user.username}</span>
      {user.emailVerified ? (
        <Badge variant="outline" className="border-transparent bg-green-100 text-green-700">
          verified
        </Badge>
      ) : (
        <Badge variant="outline" className="border-transparent bg-amber-100 text-amber-700">
          pending
        </Badge>
      )}
    </span>
  );
}
