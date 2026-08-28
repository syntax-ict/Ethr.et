"use client";

import Link from "next/link";
import { useEmployeeDashboard } from "@/features/dashboard/api";
import { useT } from "@/lib/i18n/useT";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import { Skeleton } from "@/components/ui/skeleton";
import { ArrowRight, CalendarDays, Plus, TreePalm } from "lucide-react";
import { cn } from "@/lib/utils";
import { WidgetError } from "./widget-error";

export function LeaveOverview() {
  const { t } = useT();
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
            <p className="mt-0.5 text-xs text-muted-foreground">
              {t(
                "dashboard.contact_hr_leave",
                "Contact HR to set up leave types",
              )}
            </p>
          </div>
        ) : (
          <div className="space-y-4">
            {balances.map((b, i) => {
              const pct =
                b.entitled > 0 ? Math.round((b.used / b.entitled) * 100) : 0;
              const isLow = b.remaining <= 3 && b.entitled > 0;

              return (
                <div
                  key={i}
                  className="group rounded-xl border border-border/40 p-3 transition-all duration-200 hover:border-border/80 hover:bg-muted/30"
                >
                  <div className="mb-2 flex items-center justify-between">
                    <span className="text-sm font-medium text-foreground">
                      {String(b.type)}
                    </span>
                    <div className="flex items-center gap-2">
                      {isLow && (
                        <span className="rounded-full bg-warning-soft px-1.5 py-0.5 text-[10px] font-semibold text-warning-on-soft">
                          {t("dashboard.low", "Low")}
                        </span>
                      )}
                      <span className="text-xs font-semibold tabular-nums text-foreground">
                        {b.remaining}
                        <span className="font-normal text-muted-foreground">
                          /{b.entitled}
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
                    aria-label={`${String(b.type)} leave: ${pct}% used`}
                  />
                  <div className="mt-1.5 flex justify-between text-[11px] text-muted-foreground">
                    <span>
                      {b.used} {t("dashboard.used", "used")}
                    </span>
                    <span>
                      {b.remaining} {t("dashboard.remaining", "remaining")}
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
