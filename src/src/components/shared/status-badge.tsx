import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";

const statusStyles: Record<string, string> = {
  active: "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300",
  confirmed:
    "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300",
  hired: "bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300",
  probation:
    "bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300",
  suspended:
    "bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300",
  terminated: "bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300",
  resigned: "bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300",
  retired: "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400",
  pending:
    "bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300",
  approved: "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300",
  rejected: "bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300",
  cancelled: "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400",
  completed:
    "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300",
  draft: "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400",
  processing: "bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300",
  present: "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300",
  late: "bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300",
  absent: "bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300",
  paid: "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300",
  voided: "bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300",
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
        statusStyles[status] ??
          "bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300",
        className,
      )}
    >
      {status.replace(/_/g, " ")}
    </Badge>
  );
}
