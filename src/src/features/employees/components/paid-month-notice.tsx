"use client";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { isFullDate, usePaidMonths } from "../api";

/**
 * Under a date field whose value can change someone's pay: says when the date
 * reaches payroll months already approved or paid, so a wrong date can be
 * corrected before it is saved and a right one is made knowingly. A notice
 * only; the form still saves. Shows nothing until a full date is typed and
 * while nothing is reached; a failed lookup says so in one muted line rather
 * than staying silent.
 */
export function PaidMonthNotice({
  employeeId,
  from,
  enabled = true,
}: {
  employeeId: string;
  /** `YYYY-MM-DD`, the date being typed. */
  from: string;
  /** False when this form's values cannot change pay, so the date is harmless. */
  enabled?: boolean;
}) {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const { data, isError } = usePaidMonths(employeeId, from, enabled);

  if (!enabled || !isFullDate(from)) return null;

  if (isError) {
    return (
      <p
        className="text-xs text-muted-foreground"
        data-testid="paid-month-check-failed"
      >
        {t(
          "employee.paid_months.check_failed",
          "Could not check whether this date reaches a month already paid.",
        )}
      </p>
    );
  }

  if (!data || data.count <= 0) return null;

  const params = {
    count: data.count,
    from: data.earliest_period_start
      ? formatDate(data.earliest_period_start)
      : "",
    to: data.latest_period_end ? formatDate(data.latest_period_end) : "",
  };

  return (
    <Alert variant="warning" data-testid="paid-month-notice">
      <AlertDescription>
        {data.count === 1
          ? t(
              "employee.paid_months.one",
              "This date reaches :count month already paid, from :from to :to. If it changes this person's pay, the difference is listed for finance under Payroll, Pay differences.",
              params,
            )
          : t(
              "employee.paid_months.many",
              "This date reaches :count months already paid, from :from to :to. If it changes this person's pay, the difference is listed for finance under Payroll, Pay differences.",
              params,
            )}
      </AlertDescription>
    </Alert>
  );
}
