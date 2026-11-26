"use client";

import { useEffect } from "react";
import { Bell, CheckCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageActions } from "@/components/shared/page-actions";
import { EmptyState } from "@/components/shared/empty-state";
import { PaginationControls } from "@/components/shared/pagination-controls";
import {
  useNotifications,
  useMarkAllAsRead,
  type Notification,
} from "@/features/notifications/api";
import { NotificationItem } from "@/features/notifications/components/notification-item";
import { useQueryFilters } from "@/lib/hooks/useQueryFilters";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { ErrorState } from "@/components/shared/error-state";

export default function NotificationsPage() {
  const { t } = useT();
  // The filter and the page sit in the address, so a reload keeps them and a
  // link carries them. Paged: the page showed the first 25 and nothing past
  // them (audit N83).
  const { values, set } = useQueryFilters(
    { read: "all", page: "1" },
    { read: ["all", "unread"] },
  );
  const unread = values.read === "unread";
  const page = Math.max(1, parseInt(values.page, 10) || 1);
  const { data, isLoading, isError, refetch } = useNotifications({
    page,
    unread,
  });
  const markAllRead = useMarkAllAsRead();

  // Marking items read on the Unread view can leave the page past the last one;
  // step back to it, or the page would say there is nothing while pages remain.
  const lastPage = data?.meta?.last_page;
  useEffect(() => {
    if (lastPage && page > lastPage) set({ page: String(lastPage) });
  }, [lastPage, page, set]);

  function handleMarkAllRead() {
    markAllRead.mutate(undefined, {
      onSuccess: () =>
        toast.success(
          t("notifications.all_read", "All notifications marked as read"),
        ),
    });
  }

  const notifications = data?.data ?? [];

  return (
    <div className="space-y-6">
      <PageActions
        actions={
          notifications.length > 0 && (
            <Button
              variant="outline"
              size="sm"
              onClick={handleMarkAllRead}
              disabled={markAllRead.isPending}
            >
              <CheckCheck className="mr-2 h-4 w-4" />
              {t("common.mark_all_read", "Mark all read")}
            </Button>
          )
        }
      />

      <div
        role="group"
        aria-label={t("notifications.filter_label", "Show")}
        className="flex gap-2"
      >
        {(["all", "unread"] as const).map((value) => (
          <Button
            key={value}
            variant={values.read === value ? "secondary" : "outline"}
            size="sm"
            aria-pressed={values.read === value}
            onClick={() => set({ read: value, page: "1" })}
          >
            {value === "all"
              ? t("notifications.filter_all", "All")
              : t("notifications.filter_unread", "Unread")}
          </Button>
        ))}
      </div>

      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 5 }).map((_, i) => (
            <Skeleton key={i} className="h-16 w-full" />
          ))}
        </div>
      ) : isError ? (
        <ErrorState onRetry={() => refetch()} />
      ) : notifications.length === 0 ? (
        <EmptyState
          icon={Bell}
          title={
            unread
              ? t("notifications.no_unread", "No unread notifications")
              : t("common.no_notifications", "No notifications")
          }
          description={
            unread
              ? undefined
              : t("notifications.caught_up", "No new notifications")
          }
        />
      ) : (
        <Card>
          <CardContent className="divide-y p-0">
            {notifications.map((n: Notification) => (
              <NotificationItem key={n.id} notification={n} />
            ))}
          </CardContent>
        </Card>
      )}

      <PaginationControls
        meta={data?.meta}
        onPageChange={(next) => set({ page: String(next) })}
        disabled={isLoading}
      />
    </div>
  );
}
