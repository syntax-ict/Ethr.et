"use client";

import Link from "next/link";
import { useAnnouncements } from "@/features/announcements/api";
import { useT } from "@/lib/i18n/useT";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { ArrowRight, Megaphone } from "lucide-react";
import { NewsCard } from "@/features/news/components/news-card";
import { cn } from "@/lib/utils";
import { WidgetError } from "./widget-error";

/**
 * The left edge a story carries on the dashboard card. Only urgent and
 * important ones stand out; "low" and anything new read as normal. The
 * priority's word is drawn by the shared `NewsMeta`, so it says the same
 * thing here as on the announcements page and the public news page.
 */
const priorityEdge = {
  urgent: {
    border: "border-l-status-error",
    bg: "bg-status-error/[0.03]",
  },
  high: {
    border: "border-l-status-warning",
    bg: "bg-status-warning/[0.03]",
  },
  normal: {
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

  // No status filter: AnnouncementResource has no `status` field, so filtering
  // on it dropped every row and this card always said "No announcements".
  // GET /announcements already returns only published, unexpired ones.
  const announcements = (data?.data ?? []).slice(0, 3);

  // One line when there is nothing (two when the column is narrow; the
  // status wraps under the title rather than being cut off, which the
  // Amharic layout gate flags): an empty-state illustration on every
  // dashboard made the card as tall as a full one (dashboard review,
  // 2026-11-03, PR 7). The link stays, for whoever posts the first one.
  if (announcements.length === 0) {
    return (
      <Card>
        <CardContent className="flex items-center justify-between gap-3 py-3">
          <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-0.5">
            <Megaphone
              className="h-4 w-4 shrink-0 text-muted-foreground"
              aria-hidden="true"
            />
            <span className="text-sm font-semibold text-foreground">
              {t("nav.announcements", "Announcements")}
            </span>
            <span className="text-xs text-muted-foreground">
              {t("dashboard.no_announcements", "No announcements")}
            </span>
          </div>
          <Button
            variant="ghost"
            size="sm"
            className="h-8 shrink-0 text-xs"
            asChild
          >
            <Link href="/announcements">
              {t("common.view_all", "View all")}
              <ArrowRight className="ml-1 h-3 w-3" aria-hidden="true" />
            </Link>
          </Button>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card className="transition-shadow duration-300 hover:shadow-md">
      <CardHeader className="flex flex-row items-center justify-between pb-3">
        <div className="flex items-center gap-2">
          <Megaphone className="h-4 w-4 text-muted-foreground" />
          <CardTitle
            role="heading"
            aria-level={2}
            className="text-sm font-semibold"
          >
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
        <ul className="space-y-2">
          {announcements.map((ann) => {
            const edge =
              priorityEdge[ann.priority as keyof typeof priorityEdge] ??
              priorityEdge.normal;
            return (
              <li key={ann.public_id}>
                {/*
                 * `featured` is publishing metadata: it means "first on the
                 * public website", which is the publisher's business and not
                 * the reader's. Everyone sees this card, so the star is left
                 * off here; the announcements page shows it to managers only.
                 */}
                <NewsCard
                  item={{ ...ann, featured: false }}
                  href={`/announcements?read=${ann.public_id}`}
                  linkComponent={Link}
                  density="compact"
                  headingLevel={3}
                  className={cn(
                    "border-border/60 border-l-[3px] transition-[border-color,box-shadow] duration-200 hover:border-border hover:shadow-sm",
                    edge.border,
                    edge.bg,
                  )}
                />
              </li>
            );
          })}
        </ul>
      </CardContent>
    </Card>
  );
}
