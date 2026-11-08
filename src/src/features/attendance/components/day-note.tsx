"use client";

import { CalendarOff, Gift, PartyPopper, Plane } from "lucide-react";
import { localizedName } from "@/lib/i18n/localizedName";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

/**
 * What the day is, as the API says it (`day` on `GET /attendance/my-shift` and
 * on every punch answer): a public holiday and its name, the weekend, another
 * rest day, approved leave. A working day says nothing.
 *
 * One component for every way of punching, so the web page, the phone, the QR
 * scan and the kiosk tell a person the same thing about the same day.
 */
export interface DayInfo {
  kind: string;
  weekend: boolean;
  holiday: { name: string; name_am?: string | null } | null;
}

type Translate = (
  key: string,
  fallback?: string,
  replacements?: Record<string, string | number>,
) => string;

export interface DayNoteText {
  kind: "holiday" | "weekend" | "day_off" | "on_leave";
  title: string;
  hint: string;
}

/** The sentence for a day, or null on a working day (and where unknown). */
export function dayNoteText(
  day: DayInfo | null | undefined,
  t: Translate,
  locale: string,
): DayNoteText | null {
  if (!day) return null;

  const noShift = t(
    "attendance.punch.day_no_shift",
    "No shift is expected today.",
  );

  if (day.kind === "holiday") {
    return {
      kind: "holiday",
      title: t("attendance.punch.day_holiday", "Today is :name", {
        name: localizedName(day.holiday, locale) || "-",
      }),
      hint: t(
        "attendance.punch.day_holiday_hint",
        "A public holiday. No shift is expected today.",
      ),
    };
  }

  if (day.kind === "rest_day") {
    return day.weekend
      ? {
          kind: "weekend",
          title: t("attendance.punch.day_weekend", "Happy weekend"),
          hint: noShift,
        }
      : {
          kind: "day_off",
          title: t("attendance.punch.day_off", "Today is your day off"),
          hint: noShift,
        };
  }

  if (day.kind === "on_leave") {
    return {
      kind: "on_leave",
      title: t(
        "attendance.punch.day_on_leave",
        "You are on approved leave today",
      ),
      hint: noShift,
    };
  }

  return null;
}

const ICONS = {
  holiday: Gift,
  weekend: PartyPopper,
  day_off: CalendarOff,
  on_leave: Plane,
} as const;

export function DayNote({
  day,
  className,
}: {
  day: DayInfo | null | undefined;
  className?: string;
}) {
  const { t, locale } = useT();
  const note = dayNoteText(day, t, locale);

  if (!note) return null;

  const Icon = ICONS[note.kind];

  return (
    <div
      role="status"
      data-day-kind={note.kind}
      className={cn(
        "flex items-start gap-3 rounded-lg border border-info-edge bg-info-soft px-4 py-3 text-info-on-soft",
        className,
      )}
    >
      <Icon className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
      <div className="min-w-0">
        <p className="font-semibold">{note.title}</p>
        <p className="text-sm opacity-90">{note.hint}</p>
      </div>
    </div>
  );
}
