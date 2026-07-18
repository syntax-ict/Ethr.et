import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";

// Built on the semantic status tokens (globals.css) rather than raw Tailwind
// palette classes, so badges repaint automatically for dark mode via the
// token's own light/dark value instead of a separate `dark:` override per
// status. `/15` and `/60` are Tailwind opacity modifiers on the CSS variable.
const statusStyles: Record<string, string> = {
  active: "bg-status-success/15 text-status-success",
  confirmed: "bg-status-success/15 text-status-success",
  hired: "bg-status-info/15 text-status-info",
  probation: "bg-status-warning/15 text-status-warning",
  suspended: "bg-status-warning/15 text-status-warning",
  terminated: "bg-status-error/15 text-status-error",
  resigned: "bg-muted text-muted-foreground",
  retired: "bg-muted text-muted-foreground",
  pending: "bg-status-warning/15 text-status-warning",
  approved: "bg-status-success/15 text-status-success",
  rejected: "bg-status-error/15 text-status-error",
  cancelled: "bg-muted text-muted-foreground",
  completed: "bg-status-success/15 text-status-success",
  draft: "bg-muted text-muted-foreground",
  processing: "bg-status-info/15 text-status-info",
  present: "bg-status-success/15 text-status-success",
  late: "bg-status-warning/15 text-status-warning",
  absent: "bg-status-error/15 text-status-error",
  paid: "bg-status-success/15 text-status-success",
  voided: "bg-status-error/15 text-status-error",
};

interface StatusBadgeProps {
  status: string;
  className?: string;
}

export function StatusBadge({ status, className }: StatusBadgeProps) {
  return (
    <Badge
      variant="outline"
      className={cn(
        "border-0 font-medium capitalize",
        statusStyles[status] ?? "bg-muted text-muted-foreground",
        className,
      )}
    >
      {status.replace(/_/g, " ")}
    </Badge>
  );
}
