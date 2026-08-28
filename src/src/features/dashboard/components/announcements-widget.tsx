"use client";

import Link from "next/link";
import { useAnnouncements } from "@/features/announcements/api";
import { useT } from "@/lib/i18n/useT";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { ArrowRight, Megaphone, AlertTriangle, Info } from "lucide-react";
import { timeAgo } from "@/lib/utils/date";
import { cn } from "@/lib/utils";
import { WidgetError } from "./widget-error";

const priorityConfig = {
  urgent: {
    icon: AlertTriangle,
    badge: "destructive" as const,
    border: "border-l-status-error",
    bg: "bg-status-error/[0.03]",
  },
  high: {
    icon: AlertTriangle,
    badge: "warning" as const,
    border: "border-l-status-warning",
    bg: "bg-status-warning/[0.03]",
  },
  normal: {
    icon: Info,
    badge: "secondary" as const,
    border: "border-l-interactive-primary",
    bg: "",
  },
};

export function AnnouncementsWidget() {
  const { t } = useT();
  const { data, isLoading, isError, refetch } = useAnnouncements({ page: 1 });

  if (isError) {
    return (
      <WidgetError
        title={t("dashboard.announcements", "Announcements")}
        onRetry={() => refetch()}
      />
    );
  }

  if (isLoading) {
    return (
      <Card>
        <CardHeader className="flex flex-row items-center justify-between pb-3">
          <Skeleton className="h-5 w-36" />
          <Skeleton className="h-8 w-20" />
        </CardHeader>
        <CardContent className="space-y-3">
          {Array.from({ length: 2 }).map((_, i) => (
            <Skeleton key={i} className="h-20 rounded-lg" />
          ))}
        </CardContent>
      </Card>
    );
  }

  const announcements = (data?.data ?? [])
    .filter((a) => a.status === "published")
    .slice(0, 3);

  return (
    <Card className="transition-shadow duration-300 hover:shadow-md">
      <CardHeader className="flex flex-row items-center justify-between pb-3">
        <div className="flex items-center gap-2">
          <Megaphone className="h-4 w-4 text-muted-foreground" />
          <CardTitle className="text-sm font-semibold">
            {t("nav.announcements", "Announcements")}
          </CardTitle>
        </div>
        <Button variant="ghost" size="sm" className="h-8 text-xs" asChild>
          <Link href="/announcements">
            {t("common.view_all", "View all")}
            <ArrowRight className="ml-1 h-3 w-3" />
          </Link>
        </Button>
      </CardHeader>
      <CardContent>
        {announcements.length === 0 ? (
          <div className="flex flex-col items-center py-6 text-center">
            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-muted/80">
              <Megaphone className="h-6 w-6 text-muted-foreground/60" />
            </div>
            <p className="mt-3 text-sm font-medium text-foreground">
              {t("dashboard.no_announcements", "No announcements")}
            </p>
            <p className="mt-0.5 text-xs text-muted-foreground">
              {t(
                "dashboard.announcements_will_appear",
                "Company announcements will appear here",
              )}
            </p>
          </div>
        ) : (
          <div className="space-y-2">
            {announcements.map((ann) => {
              const config =
                priorityConfig[ann.priority] ?? priorityConfig.normal;
              return (
                <Link
                  key={ann.public_id}
                  href="/announcements"
                  className={cn(
                    "group block rounded-xl border border-border/60 border-l-[3px] p-3.5 transition-all duration-200 hover:border-border hover:shadow-sm",
                    config.border,
                    config.bg,
                  )}
                >
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <p className="truncate text-sm font-semibold text-foreground">
                          {ann.title}
                        </p>
                        {ann.priority !== "normal" && (
                          <Badge variant={config.badge} className="text-[10px]">
                            {t(`common.${ann.priority}`, ann.priority)}
                          </Badge>
                        )}
                      </div>
                      <p className="mt-1 line-clamp-2 text-xs leading-relaxed text-muted-foreground">
                        {ann.body}
                      </p>
                    </div>
                  </div>
                  <p className="mt-2 text-[11px] text-muted-foreground/70">
                    {ann.published_at
                      ? timeAgo(ann.published_at)
                      : timeAgo(ann.created_at)}
                  </p>
                </Link>
              );
            })}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
