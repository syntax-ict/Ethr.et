"use client";

import Link from "next/link";
import { Bell, CheckCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { Separator } from "@/components/ui/separator";
import {
  useNotifications,
  useUnreadCount,
  useMarkAsRead,
  useMarkAllAsRead,
  notificationText,
  type Notification,
} from "../api";
import { cn } from "@/lib/utils";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";

export function NotificationBell() {
  const { t } = useT();
  const { timeAgo } = useDateFormatters();
  const { data: unread } = useUnreadCount();
  const { data: notifData } = useNotifications({ page: 1 });
  const markRead = useMarkAsRead();
  const markAllRead = useMarkAllAsRead();

  const notifications = (notifData?.data ?? []).slice(0, 5);
  const count = unread?.count ?? 0;

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="ghost" size="icon" className="relative">
          <Bell className="h-4 w-4" />
          {count > 0 && (
            <span className="absolute -right-0.5 -top-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-destructive text-[10px] font-bold text-destructive-foreground">
              {count > 99 ? "99+" : count}
            </span>
          )}
          <span className="sr-only">
            {t("common.notifications", "Notifications")}
          </span>
        </Button>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-80 p-0">
        <div className="flex items-center justify-between px-4 py-3">
          <h4 className="text-sm font-semibold">
            {t("common.notifications", "Notifications")}
          </h4>
          {count > 0 && (
            <Button
              variant="ghost"
              size="sm"
              className="h-auto px-2 py-1 text-xs"
              onClick={() => markAllRead.mutate()}
            >
              <CheckCheck className="mr-1 h-3 w-3" />
              {t("common.mark_all_read", "Mark all read")}
            </Button>
          )}
        </div>
        <Separator />
        {notifications.length === 0 ? (
          <div className="px-4 py-8 text-center">
            <Bell className="mx-auto h-8 w-8 text-muted-foreground/40" />
            <p className="mt-2 text-sm text-muted-foreground">
              {t("common.no_notifications", "No notifications")}
            </p>
          </div>
        ) : (
          <div className="max-h-80 overflow-y-auto">
            {notifications.map((n: Notification) => (
              <button
                key={n.id}
                className={cn(
                  "flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/50",
                  !n.read_at && "bg-primary/5",
                )}
                onClick={() => {
                  if (!n.read_at) markRead.mutate(n.id);
                }}
              >
                <div
                  className={cn(
                    "mt-0.5 h-2 w-2 shrink-0 rounded-full",
                    n.read_at ? "bg-transparent" : "bg-primary",
                  )}
                />
                <div className="min-w-0 flex-1">
                  <p
                    className={cn(
                      "text-sm leading-snug",
                      !n.read_at && "font-medium",
                    )}
                  >
                    {notificationText(n) ?? "New notification"}
                  </p>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {timeAgo(n.created_at)}
                  </p>
                </div>
              </button>
            ))}
          </div>
        )}
        <Separator />
        <div className="p-2">
          <Button variant="ghost" size="sm" className="w-full text-xs" asChild>
            <Link href="/notifications">
              {t("common.view_all_notifications", "View all notifications")}
            </Link>
          </Button>
        </div>
      </PopoverContent>
    </Popover>
  );
}
