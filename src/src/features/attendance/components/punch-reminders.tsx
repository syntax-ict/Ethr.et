"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { useCurrentUser } from "@/features/auth/api";
import { useMyShift, type MyShift } from "@/features/attendance/api";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import {
  reminderPlan,
  reminderStillDue,
  type ReminderKind,
} from "@/lib/attendance/punch-assistant";

/** Literal keys, so the i18n check sees each one. */
const COPY: Record<
  ReminderKind,
  { title: [string, string]; body: [string, string] }
> = {
  before_start: {
    title: ["attendance.reminder.before_start_title", "Your shift starts soon"],
    body: [
      "attendance.reminder.before_start_body",
      "Starts at :start. Check in when you arrive.",
    ],
  },
  not_checked_in: {
    title: [
      "attendance.reminder.not_checked_in_title",
      "You haven't checked in",
    ],
    body: [
      "attendance.reminder.not_checked_in_body",
      "Your shift started at :start. Check in now, or you will be marked late.",
    ],
  },
  shift_end: {
    title: ["attendance.reminder.shift_end_title", "Your shift has ended"],
    body: [
      "attendance.reminder.shift_end_body",
      "It ended at :end. Remember to check out.",
    ],
  },
};

/** Timers further out than this are set again on a later refresh. */
const HORIZON_MS = 12 * 60 * 60 * 1000;

function remindedKey(date: string, kind: ReminderKind): string {
  return `ethr:reminded:${date}:${kind}`;
}

function alreadyReminded(date: string, kind: ReminderKind): boolean {
  try {
    return window.localStorage.getItem(remindedKey(date, kind)) === "1";
  } catch {
    return false;
  }
}

function markReminded(date: string, kind: ReminderKind): void {
  try {
    window.localStorage.setItem(remindedKey(date, kind), "1");
  } catch {
    // Private browsing: at worst a reminder repeats after a reload.
  }
}

/**
 * Shift reminders on this device: before the shift starts, when the grace
 * period runs out without a check-in, and when the shift ends.
 *
 * Scheduled here rather than sent by the server: the server's scheduler is
 * woken by an external caller and can run late, and a reminder that arrives
 * after the shift started is noise. A device timer fires on time while ETHR
 * is open, in the foreground or a background tab. Each reminder is shown
 * once per day and re-checked against the server before it is shown, so a
 * punch made on another device (a kiosk, a fingerprint reader) silences it.
 *
 * With notification permission the reminder is a system notification;
 * otherwise an in-app message with a button to the punch screen.
 */
export function PunchReminders() {
  const { data: user } = useCurrentUser();
  const hasEmployee = Boolean(user?.employee_public_id);
  const { data: shift } = useMyShift(hasEmployee);
  const queryClient = useQueryClient();
  const router = useRouter();
  const { t } = useT();

  useEffect(() => {
    if (!shift) return;

    const now = new Date();
    const timers = reminderPlan(shift, now)
      .filter((r) => r.at.getTime() - now.getTime() < HORIZON_MS)
      .filter((r) => !alreadyReminded(shift.date, r.kind))
      .map((reminder) =>
        window.setTimeout(async () => {
          // The latest word: a punch elsewhere since the last refresh.
          let latest: MyShift = shift;
          try {
            latest = await queryClient.fetchQuery({
              queryKey: ["attendance", "my-shift"],
              queryFn: async () =>
                (await apiClient.get("/attendance/my-shift")).data,
              staleTime: 0,
            });
          } catch {
            // Offline: remind on what is known.
          }
          if (!reminderStillDue(reminder.kind, latest)) return;
          if (alreadyReminded(latest.date, reminder.kind)) return;
          markReminded(latest.date, reminder.kind);

          const copy = COPY[reminder.kind];
          const title = t(copy.title[0], copy.title[1]);
          const body = t(copy.body[0], copy.body[1], {
            start: latest.shift?.start_time ?? "",
            end: latest.shift?.end_time ?? "",
          });
          await notify(title, body, () => router.push("/attendance/mobile"), t);
        }, reminder.at.getTime() - now.getTime()),
      );

    return () => timers.forEach((id) => window.clearTimeout(id));
  }, [shift, queryClient, router, t]);

  return null;
}

async function notify(
  title: string,
  body: string,
  open: () => void,
  t: (key: string, fallback?: string) => string,
): Promise<void> {
  if (
    typeof Notification !== "undefined" &&
    Notification.permission === "granted" &&
    "serviceWorker" in navigator
  ) {
    try {
      const registration = await navigator.serviceWorker.getRegistration();
      if (registration) {
        await registration.showNotification(title, {
          body,
          tag: "ethr-punch-reminder",
          icon: "/app-icons/icon-192.png",
          badge: "/app-icons/badge-72.png",
          data: { url: "/attendance/mobile" },
        });
        return;
      }
    } catch {
      // Fall through to the in-app message.
    }
  }

  toast(title, {
    description: body,
    duration: 60_000,
    action: { label: t("attendance.reminder.open", "Open"), onClick: open },
  });
}
