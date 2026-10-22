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
 * limit or the 20-hour monthly limit, before anyone approves it. All of it is
 * paid; the point is that someone sees it and asks whether it was authorised.
 */
export function OvertimeLimitsBanner({ limits }: { limits: OvertimeLimits }) {
  const { t } = useT();
  if (limits.count === 0) return null;

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
              ":count employee(s) worked overtime past the limits: more than :daily hours on a day, or more than 20 hours in the month.",
              {
                count: limits.count,
                daily: hours(limits.daily_limit_minutes ?? 120),
              },
            )}
          </p>
          <p>
            {t(
              "overtime_limits.hint",
              "All of it is paid. Check that the extra hours were authorised before approving the run.",
            )}
          </p>
          <ul className="list-inside list-disc space-y-0.5">
            {limits.employees.map((e, i) => {
              const detail = e.over_monthly_limit
                ? t(
                    "overtime_limits.employee_monthly",
                    ":hours hours in the period, over the monthly limit; :days day(s) over the daily limit",
                    {
                      hours: hours(e.overtime_minutes),
                      days: e.days_over_daily_limit,
                    },
                  )
                : t(
                    "overtime_limits.employee_daily",
                    ":hours hours in the period; :days day(s) over the daily limit",
                    {
                      hours: hours(e.overtime_minutes),
                      days: e.days_over_daily_limit,
                    },
                  );
              return (
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
                  {detail}
                </li>
              );
            })}
          </ul>
        </div>
      </div>
    </section>
  );
}
