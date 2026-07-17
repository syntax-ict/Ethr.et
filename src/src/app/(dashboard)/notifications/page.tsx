"use client";

import { Bell, CheckCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import {
  useNotifications,
  useMarkAsRead,
  useMarkAllAsRead,
} from "@/features/notifications/api";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

export default function NotificationsPage() {
  const { t } = useT();
  const { data, isLoading } = useNotifications();
  const markRead = useMarkAsRead();
  const markAllRead = useMarkAllAsRead();

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
      <PageHeader
        title={t("common.notifications", "Notifications")}
        description={t(
          "notifications.description",
          "Stay updated on your activities",
        )}
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

      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 5 }).map((_, i) => (
            <Skeleton key={i} className="h-16 w-full" />
          ))}
        </div>
      ) : notifications.length === 0 ? (
        <EmptyState
          icon={Bell}
          title={t("common.no_notifications", "No notifications")}
          description={t("notifications.caught_up", "You're all caught up!")}
        />
      ) : (
        <div className="space-y-2">
          {notifications.map(
            (n: {
              id: string;
              type: string;
              data: Record<string, unknown>;
              read_at: string | null;
              created_at: string;
            }) => (
              <Card
                key={n.id}
                className={cn(
                  "cursor-pointer transition-colors hover:bg-muted/50",
                  !n.read_at && "border-l-4 border-l-primary",
                )}
                onClick={() => {
                  if (!n.read_at) {
                    markRead.mutate(n.id);
                  }
                }}
              >
                <CardContent className="flex items-start gap-3 p-4">
                  <div
                    className={cn(
                      "mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full",
                      n.read_at ? "bg-muted" : "bg-primary/10",
                    )}
                  >
                    <Bell
                      className={cn(
                        "h-4 w-4",
                        n.read_at ? "text-muted-foreground" : "text-primary",
                      )}
                    />
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className={cn("text-sm", !n.read_at && "font-medium")}>
                      {(n.data?.message as string) ?? n.type}
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                      {formatTimeAgo(n.created_at)}
                    </p>
                  </div>
                  {!n.read_at && (
                    <div className="h-2 w-2 shrink-0 rounded-full bg-primary" />
                  )}
                </CardContent>
              </Card>
            ),
          )}
        </div>
      )}
    </div>
  );
}

function formatTimeAgo(dateStr: string): string {
  const date = new Date(dateStr);
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffMin = Math.floor(diffMs / 60000);

  if (diffMin < 1) return "just now";
  if (diffMin < 60) return `${diffMin}m ago`;
  const diffHr = Math.floor(diffMin / 60);
  if (diffHr < 24) return `${diffHr}h ago`;
  const diffDay = Math.floor(diffHr / 24);
  if (diffDay < 7) return `${diffDay}d ago`;
  return date.toLocaleDateString();
}
