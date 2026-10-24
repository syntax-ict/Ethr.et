"use client";

import Link from "next/link";
import { CalendarX } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import type { PayrollAbsences } from "../api";

/**
 * Everyone in this run who missed workdays with no attendance, approved
 * leave or holiday on record, before anyone approves it. Pay was not reduced
 * for any of it: a day the system cannot explain may be leave nobody
 * recorded or a punch a device missed, and docking pay is a decision for a
 * person.
 */
export function AbsencesBanner({ absences }: { absences: PayrollAbsences }) {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  if (absences.count === 0) return null;

  return (
    <section
      aria-label={t("payroll_absences.title", "Absences nobody explained")}
      className="rounded-lg border border-warning-edge bg-warning-soft p-4 text-sm text-warning-on-soft"
    >
      <div className="flex gap-2">
        <CalendarX className="mt-0.5 h-4 w-4 shrink-0" />
        <div className="space-y-2">
          <p className="font-medium">
            {t(
              "payroll_absences.summary",
              ":count employee(s) missed :days workday(s) with no attendance, leave or holiday on record.",
              { count: absences.count, days: absences.days },
            )}
          </p>
          <p>
            {t(
              "payroll_absences.hint",
              "Pay was not reduced for these days. Before approving, check each one: it may be leave nobody recorded or a punch a device missed.",
            )}
          </p>
          <ul className="list-inside list-disc space-y-0.5">
            {absences.employees.map((e, i) => (
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
                {t("payroll_absences.employee", ":days day(s): :dates", {
                  days: e.days,
                  dates: e.dates.map(formatDate).join(", "),
                })}
              </li>
            ))}
          </ul>
        </div>
      </div>
    </section>
  );
}
