"use client";

import Link from "next/link";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useEmployeeDashboard } from "@/features/dashboard/api";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
import {
  LogIn,
  LogOut,
  Plus,
  UserPlus,
  Wallet,
  CheckSquare,
  type LucideIcon,
} from "lucide-react";

interface QuickAction {
  label: string;
  href: string;
  icon: LucideIcon;
  show: boolean;
}

/**
 * Page-level actions for the dashboard header.
 *
 * Deliberately verbs, not destinations. The previous grid offered ten
 * equal-weight cards, six of which ("Manage Employees", "View Reports",
 * "Analytics", "Shifts") were just second copies of sidebar entries — so the
 * panel cost a scan of ten options to reach something the nav already
 * provided in one click. Ten undifferentiated choices also put selection time
 * squarely in Hick's law territory for what is meant to be the fast path.
 *
 * What remains is only work the user *performs* rather than pages they visit,
 * capped at four so one primary and three secondaries fit a single row
 * without wrapping on tablet.
 */
export function QuickActions() {
  const { t } = useT();
  const { can, isSupervisor } = usePermissions();
  const { data } = useEmployeeDashboard();

  // One attendance CTA that reflects the current state, rather than offering
  // "Check In" to someone who checked in two hours ago (Nielsen #1).
  const isCheckedIn = data?.attendance_today?.status === "checked_in";

  const actions: QuickAction[] = [
    {
      label: isCheckedIn
        ? t("common.check_out", "Check Out")
        : t("common.check_in", "Check In"),
      href: "/attendance",
      icon: isCheckedIn ? LogOut : LogIn,
      show: true,
    },
    {
      label: t("common.apply_leave", "Apply for Leave"),
      href: "/leave",
      icon: Plus,
      show: true,
    },
    {
      label: t("dashboard.review_approvals", "Review Approvals"),
      href: "/approvals",
      icon: CheckSquare,
      show: isSupervisor,
    },
    {
      label: t("common.add_employee", "Add Employee"),
      href: "/employees/new",
      icon: UserPlus,
      show: can.manageEmployees,
    },
    {
      label: t("command.run_payroll", "Run Payroll"),
      href: "/payroll",
      icon: Wallet,
      show: can.processPayroll,
    },
  ]
    .filter((a) => a.show)
    .slice(0, 4);

  const [primary, ...secondary] = actions;
  if (!primary) return null;

  return (
    <div className="flex flex-wrap items-center gap-2">
      {secondary.map((a) => (
        <Button
          key={a.href}
          variant="outline"
          size="sm"
          className="h-9"
          asChild
        >
          <Link href={a.href}>
            <a.icon className="mr-1.5 h-3.5 w-3.5" />
            {a.label}
          </Link>
        </Button>
      ))}
      <Button
        size="sm"
        className="h-9 bg-interactive-primary text-text-inverse shadow-sm hover:bg-interactive-hover"
        asChild
      >
        <Link href={primary.href}>
          <primary.icon className="mr-1.5 h-3.5 w-3.5" />
          {primary.label}
        </Link>
      </Button>
    </div>
  );
}
