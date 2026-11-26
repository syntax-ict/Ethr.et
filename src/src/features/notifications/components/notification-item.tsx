"use client";

import { useRouter } from "next/navigation";
import {
  useMarkAsRead,
  notificationText,
  notificationLink,
  type Notification,
} from "../api";
import { cn } from "@/lib/utils";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";

/**
 * One notification, drawn the same in the bell and on the notifications page.
 * A button, so opening one works from the keyboard: opening marks it read and
 * follows its link.
 */
export function NotificationItem({
  notification: n,
}: {
  notification: Notification;
}) {
  const { t } = useT();
  const { timeAgo } = useDateFormatters();
  const markRead = useMarkAsRead();
  const router = useRouter();

  return (
    <button
      type="button"
      className={cn(
        "flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/50",
        !n.read_at && "bg-primary/5",
      )}
      onClick={() => {
        if (!n.read_at) markRead.mutate(n.id);
        const link = notificationLink(n);
        if (link) router.push(link);
      }}
    >
      <div
        className={cn(
          "mt-1.5 h-2 w-2 shrink-0 rounded-full",
          n.read_at ? "bg-transparent" : "bg-primary",
        )}
      />
      <div className="min-w-0 flex-1">
        <p className={cn("text-sm leading-snug", !n.read_at && "font-medium")}>
          {notificationText(n) ??
            t("common.new_notification", "New notification")}
        </p>
        <p className="mt-0.5 text-xs text-muted-foreground">
          {timeAgo(n.created_at)}
        </p>
      </div>
    </button>
  );
}
