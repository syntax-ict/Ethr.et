"use client";

import type { Timesheet } from "@/features/reports/timesheet/api";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { localizedName } from "@/lib/i18n/localizedName";
import { useT } from "@/lib/i18n/useT";

/**
 * What the timesheet counted by, in words, so a reader and an auditor can see
 * the rules without opening settings. Every figure is one the server read from
 * the shifts, the statutory rows and the attendance settings; nothing here is
 * a setting of the report's own.
 */
export function RulesApplied({ rules }: { rules: Timesheet["rules_applied"] }) {
  const { t, locale } = useT();
  const { formatTimeOfDay } = useDateFormatters();

  const shifts = (rules.shifts ?? []) as Array<{
    public_id: string;
    name: string;
    name_am: string | null;
    start_time: string;
    end_time: string;
    grace_minutes: number;
    early_departure_minutes: number;
    break_minutes: number;
  }>;
  const overtime = (rules.overtime ?? {}) as {
    night_window?: { start_hour: number; end_hour: number };
    legal_daily_limit_minutes?: number;
    legal_weekly_limit_minutes?: number;
    requires_approval?: boolean;
  };
  const autoCheckout = (rules.auto_checkout ?? {}) as {
    enabled?: boolean;
    after_minutes?: number;
  };

  return (
    <details className="mt-4 rounded-md border p-3 text-sm">
      <summary className="cursor-pointer font-semibold">
        {t("reports_page.timesheet.rules", "How this is counted")}
      </summary>
      <ul className="mt-2 list-disc space-y-1 ps-5 text-muted-foreground">
        {shifts.map((shift) => (
          <li key={shift.public_id}>
            {t(
              "reports_page.timesheet.rule_shift",
              ":name, :start to :end: late after :grace minutes, left early before :early minutes, :break minutes break taken off.",
              {
                name: localizedName(shift, locale),
                start: formatTimeOfDay(shift.start_time),
                end: formatTimeOfDay(shift.end_time),
                grace: shift.grace_minutes,
                early: shift.early_departure_minutes,
                break: shift.break_minutes,
              },
            )}
          </li>
        ))}
        <li>
          {t(
            "reports_page.timesheet.rule_pairing",
            "The first check-in to the last check-out makes the day. Punches within :minutes minutes of each other are one.",
            { minutes: Number(rules.merge_window_minutes ?? 5) },
          )}
        </li>
        <li>
          {t(
            "reports_page.timesheet.rule_night",
            "A night shift belongs to the day it started.",
          )}
        </li>
        {autoCheckout.enabled ? (
          <li>
            {t(
              "reports_page.timesheet.rule_auto_checkout",
              "A punch left open is closed at the shift's end after :minutes minutes, and shown as a missing punch until someone confirms it.",
              { minutes: autoCheckout.after_minutes ?? 0 },
            )}
          </li>
        ) : null}
        <li>
          {t(
            "reports_page.timesheet.rule_overtime",
            "Overtime is split by the rate payroll pays it at. The night window is :start:00 to :end:00. The legal limits are :daily hours a day and :weekly hours a week.",
            {
              start: overtime.night_window?.start_hour ?? 22,
              end: overtime.night_window?.end_hour ?? 6,
              daily: (overtime.legal_daily_limit_minutes ?? 240) / 60,
              weekly: (overtime.legal_weekly_limit_minutes ?? 720) / 60,
            },
          )}
        </li>
        <li>
          {overtime.requires_approval
            ? t(
                "reports_page.timesheet.rule_approval_on",
                "Overtime has to be approved first. Unapproved overtime is shown, and payroll still pays what was worked.",
              )
            : t(
                "reports_page.timesheet.rule_approval_off",
                "Overtime does not have to be approved first, so none is shown as unapproved.",
              )}
        </li>
        <li>
          {t(
            "reports_page.timesheet.rule_absence",
            "An absence is listed and is not deducted from pay. Payroll deducts unpaid leave and unpaid sick days, and the lateness a correction approved.",
          )}
        </li>
      </ul>
    </details>
  );
}
