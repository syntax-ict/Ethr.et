"use client";

import Link from "next/link";
import { useNotifications } from "@/features/notifications/api";
import { useT } from "@/lib/i18n/useT";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  ArrowRight,
  Bell,
  CalendarDays,
  CheckSquare,
  Clock,
  FileText,
  Users,
  Wallet,
  type LucideIcon,
} from "lucide-react";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { WidgetError } from "./widget-error";
import { cn } from "@/lib/utils";

const TYPE_CONFIG: Record<string, { icon: LucideIcon; color: string }> = {
  leave_approved: { icon: CalendarDays, color: "text-status-success" },
  leave_rejected: { icon: CalendarDays, color: "text-status-error" },
  leave_requested: { icon: CalendarDays, color: "text-status-warning" },
  attendance_marked: { icon: Clock, color: "text-status-info" },
  payslip_generated: { icon: Wallet, color: "text-interactive-primary" },
  approval_required: { icon: CheckSquare, color: "text-status-warning" },
  employee_added: { icon: Users, color: "text-status-success" },
  document_uploaded: { icon: FileText, color: "text-interactive-primary" },
};

export function RecentActivity() {
  const { timeAgo } = useDateFormatters();
  const { t } = useT();
  const { data, isLoading, isError, refetch } = useNotifications({ page: 1 });

  if (isError) {
    return (
      <WidgetError
        title={t("dashboard.recent_activity", "Recent Activity")}
        onRetry={() => refetch()}
      />
    );
  }

  if (isLoading) {
    return (
      <Card>
        <CardHeader className="flex flex-row items-center justify-between pb-3">
          <Skeleton className="h-5 w-32" />
          <Skeleton className="h-8 w-20" />
        </CardHeader>
        <CardContent className="space-y-3">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="flex items-start gap-3">
              <Skeleton className="h-8 w-8 rounded-lg" />
              <div className="flex-1 space-y-1.5">
                <Skeleton className="h-3.5 w-3/4" />
                <Skeleton className="h-3 w-1/3" />
              </div>
            </div>
          ))}
        </CardContent>
      </Card>
    );
  }

  const items = data?.data?.slice(0, 6) ?? [];

  return (
    <Card className="transition-shadow duration-300 hover:shadow-md">
      <CardHeader className="flex flex-row items-center justify-between pb-3">
        <CardTitle className="text-sm font-semibold">
          {t("dashboard.recent_activity", "Recent Activity")}
        </CardTitle>
        <Button variant="ghost" size="sm" className="h-8 text-xs" asChild>
          <Link href="/notifications">
            {t("common.view_all", "View all")}
            <ArrowRight className="ml-1 h-3 w-3" />
          </Link>
        </Button>
      </CardHeader>
      <CardContent>
        {items.length === 0 ? (
          <div className="flex flex-col items-center py-8 text-center">
            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-muted/80">
              <Bell className="h-6 w-6 text-muted-foreground/60" />
            </div>
            <p className="mt-3 text-sm font-medium text-foreground">
              {t("dashboard.no_activity", "No recent activity")}
            </p>
            <p className="mt-0.5 text-xs text-muted-foreground">
              {t(
                "dashboard.activity_will_appear",
                "Your activity will appear here",
              )}
            </p>
          </div>
        ) : (
          <div className="relative space-y-0">
            {/* Timeline connector */}
            <div className="absolute bottom-0 left-4 top-2 w-px bg-border/60" />
            {items.map((notification) => {
              const config = TYPE_CONFIG[notification.type] ?? {
                icon: Bell,
                color: "text-muted-foreground",
              };
              const Icon = config.icon;
              const notifData = notification.data as Record<string, string>;
              const message =
                notifData.message ??
                notifData.title ??
                notification.type.replace(/_/g, " ");

              return (
                <div
                  key={notification.id}
                  className={cn(
                    "group relative flex items-start gap-3 rounded-lg px-1.5 py-2.5 transition-colors duration-150 hover:bg-muted/50",
                    !notification.read_at && "bg-interactive-primary/[0.03]",
                  )}
                >
                  <div className="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-card ring-1 ring-border/60 transition-all duration-200 group-hover:ring-border">
                    <Icon className={cn("h-3.5 w-3.5", config.color)} />
                  </div>
                  <div className="min-w-0 flex-1 pt-0.5">
                    <p
                      className={cn(
                        "text-sm leading-snug",
                        notification.read_at
                          ? "text-muted-foreground"
                          : "font-medium text-foreground",
                      )}
                    >
                      {message}
                    </p>
                    <p className="mt-0.5 text-[11px] text-muted-foreground/80">
                      {timeAgo(notification.created_at)}
                    </p>
                  </div>
                  {!notification.read_at && (
                    <span className="mt-2 h-2 w-2 shrink-0 rounded-full bg-interactive-primary" />
                  )}
                </div>
              );
            })}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
