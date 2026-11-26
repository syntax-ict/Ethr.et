"use client";

import Link from "next/link";
import { ArrowLeft } from "lucide-react";
import { RoleGate } from "@/components/shared/role-gate";
import { PlanFeatureNotice } from "@/components/shared/plan-feature-notice";
import { usePlanFeatures } from "@/features/auth/api";
import { TimesheetView } from "@/features/reports/timesheet/timesheet-view";
import { useT } from "@/lib/i18n/useT";

export default function MonthlyTimesheetPage() {
  const { t } = useT();
  const plan = usePlanFeatures();

  return (
    <RoleGate anyPermission={["viewReports"]}>
      <div className="space-y-4">
        <Link
          href="/reports?tab=quick"
          className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground print:hidden"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden />
          {t("reports_page.timesheet.back", "Reports")}
        </Link>
        {plan.has("reports") ? (
          <TimesheetView />
        ) : (
          <PlanFeatureNotice feature="reports" />
        )}
      </div>
    </RoleGate>
  );
}
