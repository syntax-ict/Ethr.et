"use client";

import { useState } from "react";
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
  useMarkAllAsRead,
  type Notification,
} from "../api";
import { NotificationItem } from "./notification-item";
import { useT } from "@/lib/i18n/useT";

export function NotificationBell() {
  const { t } = useT();
  const [open, setOpen] = useState(false);
  const { data: unread } = useUnreadCount();
  // The badge needs only the count; the list is fetched when the popover
  // opens, and stays cached for the next time.
  const { data: notifData } = useNotifications({ page: 1 }, open);
  const markAllRead = useMarkAllAsRead();

  const notifications = (notifData?.data ?? []).slice(0, 5);
  const count = unread?.count ?? 0;

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          className="relative text-muted-foreground hover:text-foreground"
        >
          <Bell className="h-4 w-4" aria-hidden />
          {count > 0 && (
            <span
              aria-hidden
              className="absolute -right-0.5 -top-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-destructive text-[10px] font-bold text-destructive-foreground"
            >
              {count > 99 ? "99+" : count}
            </span>
          )}
          <span className="sr-only">
            {count > 0
              ? t(
                  "common.notifications_unread",
                  "Notifications, :count unread",
                  {
                    count,
                  },
                )
              : t("common.notifications", "Notifications")}
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
              <NotificationItem key={n.id} notification={n} />
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
