"use client";

import Link from "next/link";
import { CalendarX } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { useMyAttendanceExceptions } from "@/features/payroll/reconciliation";

/**
 * The caller's own days payroll will settle this month: absences with
 * nothing on record, and lateness waiting on a decision. An employee who was
 * at work says so here with a missed-punch claim, before payroll settles the
 * day as unpaid. Nothing renders when there is nothing to say.
 */
export function MyExceptionsCard() {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const { data } = useMyAttendanceExceptions();

  const items = (data?.exceptions ?? []).filter(
    (e) =>
      e.state !== "resolved" &&
      (e.type === "unexplained_absence" ||
        e.type === "late_arrival" ||
        e.type === "early_leave"),
  );
  if (items.length === 0) return null;

  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center gap-2 text-base">
          <CalendarX className="h-4 w-4" />
          {t("attendance.my_exceptions.title")}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-2">
        <p className="text-sm text-muted-foreground">
          {t("attendance.my_exceptions.desc")}
        </p>
        <ul className="divide-y divide-border">
          {items.map((e) => (
            <li
              key={`${e.type}-${e.date}`}
              className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
            >
              <span>
                <span className="font-medium">{formatDate(e.date)}</span>
                {": "}
                {e.type === "unexplained_absence"
                  ? t("attendance.my_exceptions.absent")
                  : t("attendance.my_exceptions.late", undefined, {
                      minutes: String(e.minutes ?? 0),
                    })}
              </span>
              {e.state === "pending" ? (
                <span className="text-muted-foreground">
                  {t("attendance.my_exceptions.pending")}
                </span>
              ) : e.type === "unexplained_absence" ? (
                <Button asChild size="sm" variant="outline">
                  <Link href={`/attendance/corrections?claim=${e.date}`}>
                    {t("attendance.my_exceptions.was_at_work")}
                  </Link>
                </Button>
              ) : null}
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
