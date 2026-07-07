// A status pill built on the shared shadcn <Badge>. It maps a tenant / host
// status string to a consistent tone so the whole tenancy UI reads the same way.
import { Badge } from "@ui/badge";
import { cn } from "@lib/utils";

// Semantic tones layered over the shadcn Badge (which ships default/secondary/
// destructive/outline). We use `outline` as the base and tint via className so
// success/warning stay on-brand with the design tokens.
const TONE: Record<string, string> = {
  active: "border-transparent bg-green-100 text-green-700",
  verified: "border-transparent bg-green-100 text-green-700",
  primary: "border-transparent bg-indigo-100 text-indigo-700",
  provisioning: "border-transparent bg-amber-100 text-amber-700",
  pending: "border-transparent bg-amber-100 text-amber-700",
  suspended: "border-transparent bg-red-100 text-red-700",
  failed: "border-transparent bg-red-100 text-red-700",
  deleted: "border-transparent bg-red-100 text-red-700",
};

export function StatusBadge({ status }: { status: string }) {
  const tone = TONE[status.toLowerCase()];
  return (
    <Badge variant="outline" className={cn("capitalize", tone)}>
      {status}
    </Badge>
  );
}
