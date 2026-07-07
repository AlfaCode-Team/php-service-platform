// A shared tenant identity chip built on the shared shadcn <Avatar>. Shows the
// tenant-name initials plus the name and slug. Consumers import it as `@tenancy`.
import { Avatar, AvatarFallback } from "@ui/avatar";
import type { TenantSummary } from "../lib/client";

export function TenantBadge({ tenant }: { tenant: Pick<TenantSummary, "name" | "slug"> }) {
  const initials = tenant.name.slice(0, 2).toUpperCase();
  return (
    <span className="inline-flex items-center gap-2">
      <Avatar className="h-8 w-8 rounded-md">
        <AvatarFallback className="rounded-md bg-primary text-xs font-semibold text-primary-foreground">
          {initials}
        </AvatarFallback>
      </Avatar>
      <span className="flex flex-col leading-tight">
        <span className="font-medium">{tenant.name}</span>
        <span className="text-xs text-muted-foreground">{tenant.slug}</span>
      </span>
    </span>
  );
}
