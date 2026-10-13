"use client";

import Link from "next/link";
import { UserPlus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";

/**
 * Shown in place of "My Day" to an account with no employee record.
 *
 * An organisation's first admin registers before any employee exists, and
 * their "My Day" was four tiles of dashes — attendance, leave, payslip and
 * holiday all unavailable, with nothing saying why. Adding themselves as an
 * employee with "Create login" ticked links the record to this account.
 */
export function LinkEmployeeCallout() {
  const { t } = useT();
  const { can } = usePermissions();

  return (
    <div className="flex flex-col gap-4 rounded-xl border border-dashed border-border-strong bg-card p-5 sm:flex-row sm:items-center sm:justify-between">
      <div className="flex min-w-0 items-start gap-3">
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-info-soft text-info-on-soft">
          <UserPlus className="h-[18px] w-[18px]" aria-hidden="true" />
        </span>
        <div className="min-w-0">
          <p className="text-sm font-medium text-foreground">
            {t(
              "dashboard.no_employee_title",
              "Your account has no employee record",
            )}
          </p>
          <p className="mt-0.5 text-sm text-muted-foreground">
            {can.manageEmployees
              ? t(
                  "dashboard.no_employee_admin",
                  "Add yourself as an employee with your own email and tick “Create login” to check in, request leave and see payslips.",
                )
              : t(
                  "dashboard.no_employee_ask",
                  "Ask an HR admin to add you as an employee to check in, request leave and see payslips.",
                )}
          </p>
        </div>
      </div>
      {can.manageEmployees && (
        <Button asChild variant="outline" size="sm" className="shrink-0">
          <Link href="/employees/new">
            {t("dashboard.add_myself", "Add me as an employee")}
          </Link>
        </Button>
      )}
    </div>
  );
}
