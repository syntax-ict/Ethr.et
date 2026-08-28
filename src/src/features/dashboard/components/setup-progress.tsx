"use client";

import Link from "next/link";
import { useEmployeeDashboard } from "@/features/dashboard/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import {
  AlertCircle,
  ArrowRight,
  Building2,
  Users,
  Clock,
  CalendarDays,
  CheckCircle2,
} from "lucide-react";

const SETUP_STEPS = [
  { key: "org_profile", icon: Building2, labelKey: "setup.org_profile" },
  { key: "departments", icon: Building2, labelKey: "setup.departments" },
  { key: "employees", icon: Users, labelKey: "setup.employees" },
  { key: "shifts", icon: Clock, labelKey: "setup.shifts" },
  { key: "leave_types", icon: CalendarDays, labelKey: "setup.leave_types" },
];

export function SetupProgress() {
  const { t } = useT();
  const { can } = usePermissions();
  const { data } = useEmployeeDashboard();

  if (!can.manageSettings || data?.onboarding_complete !== false) return null;

  const summary = data.tenant_summary;
  const completed: string[] = [];
  if (summary) {
    if (summary.department_count > 0)
      completed.push("org_profile", "departments");
    if (summary.employee_count > 0) completed.push("employees");
    if (summary.branch_count > 0) completed.push("shifts");
  }
  const uniqueCompleted = [...new Set(completed)];
  const pct = Math.round((uniqueCompleted.length / SETUP_STEPS.length) * 100);

  return (
    <Card className="group border-brand-accent/30 bg-gradient-to-r from-brand-accent/5 to-transparent transition-all duration-300 hover:border-brand-accent/50 hover:shadow-md">
      <CardContent className="p-5">
        <div className="flex items-start gap-4">
          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-accent/10 transition-transform duration-300 group-hover:scale-110">
            <AlertCircle className="h-5 w-5 text-brand-accent" />
          </div>
          <div className="min-w-0 flex-1">
            <div className="flex items-center justify-between gap-4">
              <div>
                <p className="text-sm font-semibold text-foreground">
                  {t("dashboard.complete_setup", "Complete Setup")}
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                  {t(
                    "dashboard.onboarding_incomplete",
                    "Your organization setup is incomplete. Complete it to unlock all features.",
                  )}
                </p>
              </div>
              <Button
                size="sm"
                className="shrink-0 bg-brand-accent text-brand-accent-foreground hover:bg-brand-accent/90"
                asChild
              >
                <Link href="/setup/guided">
                  {t("dashboard.finish_onboarding", "Finish Setup")}
                  <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
                </Link>
              </Button>
            </div>
            <div className="mt-4">
              <div className="mb-2 flex items-center justify-between">
                <span className="text-xs font-medium text-muted-foreground">
                  {t("setup.progress", "Progress")}
                </span>
                <span className="text-xs font-semibold tabular-nums text-foreground">
                  {pct}%
                </span>
              </div>
              <Progress
                value={pct}
                className="h-1.5"
                label={t("setup.progress", "Progress")}
              />
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
              {SETUP_STEPS.map((step) => {
                const done = uniqueCompleted.includes(step.key);
                const Icon = done ? CheckCircle2 : step.icon;
                return (
                  <span
                    key={step.key}
                    className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium transition-colors ${
                      done
                        ? "bg-success-soft text-success-on-soft"
                        : "bg-muted text-muted-foreground"
                    }`}
                  >
                    <Icon className="h-3 w-3" />
                    {t(step.labelKey, step.key.replace(/_/g, " "))}
                  </span>
                );
              })}
            </div>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
