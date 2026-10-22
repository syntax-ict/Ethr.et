"use client";

import Link from "next/link";
import { Clock } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import type { OvertimeLimits } from "../api";

function hours(minutes: number): string {
  const h = minutes / 60;
  return Number.isInteger(h) ? String(h) : h.toFixed(1);
}

/**
 * Everyone in this run who worked overtime past the organisation's daily
 * limit, or the legal four hours a day or twelve a week (Proclamation
 * 1156/2019 Art. 67), before anyone approves it. All of it is paid; the
 * point is that someone sees it and asks whether it was authorised.
 */
export function OvertimeLimitsBanner({ limits }: { limits: OvertimeLimits }) {
  const { t } = useT();
  if (limits.count === 0) return null;

  const dayLimit = hours(limits.legal_daily_limit_minutes ?? 240);
  const weekLimit = hours(limits.legal_weekly_limit_minutes ?? 720);

  return (
    <section
      aria-label={t("overtime_limits.title", "Overtime past the limits")}
      className="rounded-lg border border-warning-edge bg-warning-soft p-4 text-sm text-warning-on-soft"
    >
      <div className="flex gap-2">
        <Clock className="mt-0.5 h-4 w-4 shrink-0" />
        <div className="space-y-2">
          <p className="font-medium">
            {t(
              "overtime_limits.summary",
              ":count employee(s) worked overtime past a limit: your organisation's :own_limit hours a day, or the legal :day_limit hours a day or :week_limit hours a week.",
              {
                count: limits.count,
                own_limit: hours(limits.daily_limit_minutes ?? 120),
                day_limit: dayLimit,
                week_limit: weekLimit,
              },
            )}
          </p>
          <p>
            {t(
              "overtime_limits.hint",
              "All of it is paid. Check that the extra hours were authorised before approving the run. Work on rest days and public holidays is paid at its own rates and not counted here.",
            )}
          </p>
          <ul className="list-inside list-disc space-y-0.5">
            {limits.employees.map((e, i) => (
              <li key={`${e.employee_public_id ?? i}`}>
                {e.employee_public_id ? (
                  <Link
                    href={`/employees/${e.employee_public_id}`}
                    className="font-medium underline underline-offset-2"
                  >
                    {e.employee_name}
                  </Link>
                ) : (
                  <span className="font-medium">{e.employee_name}</span>
                )}
                {": "}
                {t(
                  "overtime_limits.employee",
                  ":hours hours of overtime. Over your limit on :over_own day(s), over :day_limit hours on :over_day day(s), over :week_limit hours in :over_week week(s).",
                  {
                    hours: hours(e.overtime_minutes),
                    over_own: e.days_over_daily_limit,
                    over_day: e.days_over_legal_daily_limit,
                    over_week: e.weeks_over_legal_weekly_limit,
                    day_limit: dayLimit,
                    week_limit: weekLimit,
                  },
                )}
                {(e.days_over_approved ?? 0) > 0 && (
                  <>
                    {" "}
                    {t(
                      "overtime_limits.employee_unapproved",
                      "Past what was approved on :days day(s).",
                      { days: e.days_over_approved ?? 0 },
                    )}
                  </>
                )}
              </li>
            ))}
          </ul>
        </div>
      </div>
    </section>
  );
}
