"use client";

import Link from "next/link";
import { useManagerDashboard } from "@/features/dashboard/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { ArrowRight, CalendarDays, FileText, CheckCircle } from "lucide-react";
import { WidgetError } from "./widget-error";

export function PendingApprovalsPanel() {
  const { t } = useT();
  const { isSupervisor } = usePermissions();
  const query = useManagerDashboard(isSupervisor);
  const { data, isLoading, isError, refetch } = query;

  if (!isSupervisor) return null;

  if (isError) {
    return (
      <WidgetError
        title={t("dashboard.pending_approvals", "Pending Approvals")}
        onRetry={() => refetch()}
      />
    );
  }

  if (isLoading) {
    return (
      <Card>
        <CardHeader className="flex flex-row items-center justify-between pb-3">
          <Skeleton className="h-5 w-40" />
          <Skeleton className="h-8 w-20" />
        </CardHeader>
        <CardContent className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-16 rounded-lg" />
          ))}
        </CardContent>
      </Card>
    );
  }

  if (!data) return null;

  const { pending_approvals } = data;
  const total = pending_approvals.total;
  const leaveCount = pending_approvals.leave;

  const items = [
    {
      icon: CalendarDays,
      label: t("dashboard.leave_requests", "Leave Requests"),
      count: leaveCount,
      href: "/approvals?type=leave",
      tone: "warning" as const,
    },
    {
      icon: FileText,
      label: t("dashboard.other_approvals", "Other Requests"),
      count: total - leaveCount,
      href: "/approvals",
      tone: "info" as const,
    },
  ].filter((item) => item.count > 0);

  return (
    <Card className="transition-shadow duration-300 hover:shadow-md">
      <CardHeader className="flex flex-row items-center justify-between pb-3">
        <div className="flex items-center gap-2">
          <CardTitle className="text-sm font-semibold">
            {t("dashboard.pending_approvals", "Pending Approvals")}
          </CardTitle>
          {total > 0 && (
            <Badge
              variant="destructive"
              className="h-5 min-w-5 justify-center rounded-full px-1.5 text-[10px] font-bold tabular-nums"
            >
              {total}
            </Badge>
          )}
        </div>
        <Button variant="ghost" size="sm" className="h-8 text-xs" asChild>
          <Link href="/approvals">
            {t("common.view_all", "View all")}
            <ArrowRight className="ml-1 h-3 w-3" />
          </Link>
        </Button>
      </CardHeader>
      <CardContent>
        {items.length === 0 ? (
          <div className="flex flex-col items-center py-8 text-center">
            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-status-success/10">
              <CheckCircle className="h-6 w-6 text-status-success" />
            </div>
            <p className="mt-3 text-sm font-medium text-foreground">
              {t("dashboard.all_caught_up", "All caught up!")}
            </p>
            <p className="mt-0.5 text-xs text-muted-foreground">
              {t(
                "dashboard.no_pending_approvals",
                "No pending approvals at the moment",
              )}
            </p>
          </div>
        ) : (
          <div className="space-y-2">
            {items.map((item) => {
              const Icon = item.icon;
              return (
                <Link
                  key={item.href}
                  href={item.href}
                  className="group flex items-center justify-between rounded-xl border border-border/60 p-3.5 transition-all duration-200 hover:border-border hover:bg-muted/50 hover:shadow-sm"
                >
                  <div className="flex items-center gap-3">
                    <div
                      className={`flex h-9 w-9 items-center justify-center rounded-lg ${
                        item.tone === "warning"
                          ? "bg-status-warning/10"
                          : "bg-status-info/10"
                      }`}
                    >
                      <Icon
                        className={`h-4 w-4 ${
                          item.tone === "warning"
                            ? "text-status-warning"
                            : "text-status-info"
                        }`}
                      />
                    </div>
                    <div>
                      <p className="text-sm font-medium text-foreground">
                        {item.label}
                      </p>
                      <p className="text-xs text-muted-foreground">
                        {t("dashboard.awaiting_review", "Awaiting your review")}
                      </p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="text-lg font-bold tabular-nums text-foreground">
                      {item.count}
                    </span>
                    <ArrowRight className="h-4 w-4 text-muted-foreground transition-transform duration-200 group-hover:translate-x-0.5" />
                  </div>
                </Link>
              );
            })}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
