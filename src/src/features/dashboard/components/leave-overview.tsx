"use client";

import Link from "next/link";
import { useEmployeeDashboard } from "@/features/dashboard/api";
import { useT } from "@/lib/i18n/useT";
import { localizedName } from "@/lib/i18n/localizedName";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import { Skeleton } from "@/components/ui/skeleton";
import { ArrowRight, CalendarDays, Plus, TreePalm } from "lucide-react";
import { cn } from "@/lib/utils";
import { WidgetError } from "./widget-error";
import { sickNextTierLabel } from "@/features/leave/sick-leave";

export function LeaveOverview() {
  const { t, locale } = useT();
  const { can } = usePermissions();
  const { data, isLoading, isError, refetch } = useEmployeeDashboard();

  if (isError) {
    return (
      <WidgetError
        title={t("dashboard.leave_balances", "Leave Balances")}
        onRetry={() => refetch()}
      />
    );
  }

  if (isLoading) {
    return (
      <Card>
        <CardHeader className="pb-3">
          <Skeleton className="h-5 w-32" />
        </CardHeader>
        <CardContent className="space-y-4">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-12 rounded-lg" />
          ))}
        </CardContent>
      </Card>
    );
  }

  const balances = data?.leave_balances ?? [];

  return (
    <Card className="transition-shadow duration-300 hover:shadow-md">
      <CardHeader className="flex flex-row items-center justify-between pb-3">
        <div className="flex items-center gap-2">
          <CalendarDays className="h-4 w-4 text-muted-foreground" />
          <CardTitle className="text-sm font-semibold">
            {t("dashboard.leave_balances", "Leave Balances")}
          </CardTitle>
        </div>
        <div className="flex items-center gap-1">
          <Button variant="ghost" size="sm" className="h-8 text-xs" asChild>
            <Link href="/leave">
              <Plus className="mr-1 h-3 w-3" />
              {t("common.apply", "Apply")}
            </Link>
          </Button>
          <Button variant="ghost" size="sm" className="h-8 text-xs" asChild>
            <Link href="/leave">
              {t("common.view_all", "View all")}
              <ArrowRight className="ml-1 h-3 w-3" />
            </Link>
          </Button>
        </div>
      </CardHeader>
      <CardContent>
        {balances.length === 0 ? (
          <div className="flex flex-col items-center py-6 text-center">
            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-muted/80">
              <TreePalm className="h-6 w-6 text-muted-foreground/60" />
            </div>
            <p className="mt-3 text-sm font-medium text-foreground">
              {t("dashboard.no_leave_configured", "No leave configured")}
            </p>
            {can.manageLeaveTypes ? (
              <Button variant="outline" size="sm" className="mt-3" asChild>
                <Link href="/settings/leave-types">
                  {t("dashboard.set_up_leave_types", "Set up leave types")}
                </Link>
              </Button>
            ) : (
              <p className="mt-0.5 text-xs text-muted-foreground">
                {t(
                  "dashboard.contact_hr_leave",
                  "Contact HR to set up leave types",
                )}
              </p>
            )}
          </div>
        ) : (
          <div className="space-y-4">
            {balances.map((b, i) => {
              // Art. 85 sick leave counts days taken against six months
              // within twelve, not the 30-day entitlement, which a long
              // illness drove below zero (K-50).
              const sick = b.sick_leave;
              const entitled = sick ? sick.days_allowed : b.entitled;
              const used = sick ? sick.days_taken : b.used;
              const remaining = sick
                ? sick.days_allowed - sick.days_taken
                : Math.max(0, b.remaining);
              // Null once the leave type has been deleted. The Amharic name
              // travels with it (dashboard review, 2026-11-03, PR 10).
              const type = b.type
                ? localizedName({ name: b.type, name_am: b.type_am }, locale)
                : t("dashboard.leave", "Leave");
              const pct =
                entitled > 0 ? Math.round((used / entitled) * 100) : 0;
              const isLow = !sick && remaining <= 3 && entitled > 0;

              return (
                <div
                  key={i}
                  className="group rounded-xl border border-border/40 p-3 transition-all duration-200 hover:border-border/80 hover:bg-muted/30"
                >
                  <div className="mb-2 flex items-center justify-between">
                    <span className="text-sm font-medium text-foreground">
                      {type}
                    </span>
                    <div className="flex items-center gap-2">
                      {isLow && (
                        <span className="rounded-full bg-warning-soft px-1.5 py-0.5 text-caption font-semibold text-warning-on-soft">
                          {t("dashboard.low", "Low")}
                        </span>
                      )}
                      <span className="text-xs font-semibold tabular-nums text-foreground">
                        {sick ? used : remaining}
                        <span className="font-normal text-muted-foreground">
                          /{entitled}
                        </span>
                      </span>
                    </div>
                  </div>
                  <Progress
                    value={pct}
                    className={cn(
                      "h-1.5 transition-all",
                      isLow && "[&>div]:bg-status-warning",
                    )}
                    aria-label={t(
                      "dashboard.leave_usage_aria",
                      ":type leave: :pct% used",
                      { type, pct },
                    )}
                  />
                  <div className="mt-1.5 flex justify-between text-caption text-muted-foreground">
                    <span>
                      {t("dashboard.used_count", ":count used", {
                        count: used,
                      })}
                    </span>
                    <span>
                      {sick
                        ? sickNextTierLabel(t, sick.next_tier)
                        : t("dashboard.remaining_count", ":count remaining", {
                            count: remaining,
                          })}
                    </span>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
