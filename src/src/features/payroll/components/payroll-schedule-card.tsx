"use client";

import { useState } from "react";
import { CalendarClock, Loader2, Save } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { FormField } from "@/components/patterns/FormField";
import { useSettings, useUpdateSettings } from "@/features/settings/api";
import { ethiopianMonthName } from "@/lib/calendar/ethiopian";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { ErrorState } from "@/components/shared/error-state";

/**
 * Numbers stay strings while they are being typed: a `parseInt(...) || 1`
 * snapped a cleared field back to 1 (or 60) before the user had typed the
 * next digit. They are checked on save and turned into integers then.
 */
interface ScheduleForm {
  run_day: string;
  fiscal_year_start_month: number;
  pagumen_proration_strategy: "full_month" | "daily_rate";
  working_days_per_month: string;
  retirement_age: string;
}

type NumericField = "run_day" | "retirement_age" | "working_days_per_month";
type ScheduleErrors = Partial<Record<NumericField, string>>;

const RUN_DAY = { min: 1, max: 28 };
const WORKING_DAYS = { min: 20, max: 31 };
const RETIREMENT_AGE = { min: 45, max: 75 };

/** Pagume is not a month a fiscal year starts in. */
const MONTHS = Array.from({ length: 12 }, (_, i) => i + 1);

const PAY_PERIOD_LABEL: Record<string, string> = {
  monthly: "Monthly",
  weekly: "Weekly",
  biweekly: "Every two weeks",
};

const PAY_PERIOD_HELP: Record<string, string> = {
  monthly: "Payroll runs once a month.",
  weekly: "Payroll runs once a week.",
  biweekly: "Payroll runs every two weeks.",
};

function isWholeNumberIn(
  value: string,
  range: { min: number; max: number },
): boolean {
  if (!/^\d+$/.test(value.trim())) return false;
  const n = Number(value);
  return n >= range.min && n <= range.max;
}

/**
 * Payroll schedule and year: run day, fiscal-year start, how Pagume is paid,
 * and the retirement age (read-only pay period). These live on the tenant
 * settings JSON and persist via PUT /settings — surfaced here so all payroll
 * configuration sits on the Payroll Rules page instead of the general Settings
 * hub root.
 *
 * Fiscal-year start, Pagume proration and retirement age were accepted by the
 * API and read by PayrollEngine and the retirement cases, but no screen set
 * them, so every organisation ran on the defaults (audit N82).
 */
export function PayrollScheduleCard() {
  const { t, locale } = useT();

  const { data, isLoading, isError, refetch } = useSettings();

  const persisted: ScheduleForm = {
    run_day: String(data?.payroll?.run_day ?? 25),
    fiscal_year_start_month: data?.payroll?.fiscal_year_start_month ?? 1,
    pagumen_proration_strategy:
      data?.payroll?.pagumen_proration_strategy === "daily_rate"
        ? "daily_rate"
        : "full_month",
    working_days_per_month: String(data?.payroll?.working_days_per_month ?? 22),
    retirement_age: String(data?.payroll?.retirement_age ?? 60),
  };
  const payPeriod = data?.payroll?.pay_period ?? "monthly";

  // Until the user edits, the form is the server's values; adopting them this
  // way needs no effect and never clobbers an edit in progress.
  const [edits, setEdits] = useState<ScheduleForm | null>(null);
  const [errors, setErrors] = useState<ScheduleErrors>({});
  const form = edits ?? persisted;

  function update<K extends keyof ScheduleForm>(
    key: K,
    value: ScheduleForm[K],
  ) {
    setEdits({ ...form, [key]: value });
    if (
      key === "run_day" ||
      key === "retirement_age" ||
      key === "working_days_per_month"
    ) {
      const field = key as NumericField;
      setErrors((prev) =>
        prev[field] ? { ...prev, [field]: undefined } : prev,
      );
    }
  }

  const save = useUpdateSettings();

  function saveSchedule() {
    const next: ScheduleErrors = {};
    if (!isWholeNumberIn(form.run_day, RUN_DAY)) {
      next.run_day = t(
        "payroll_config.run_day_range",
        "Enter a day from 1 to 28",
      );
    }
    if (!isWholeNumberIn(form.retirement_age, RETIREMENT_AGE)) {
      next.retirement_age = t(
        "payroll_config.retirement_age_range",
        "Enter an age from 45 to 75",
      );
    }
    if (!isWholeNumberIn(form.working_days_per_month, WORKING_DAYS)) {
      next.working_days_per_month = t(
        "payroll_config.working_days_per_month_range",
        "Enter a number of days from 20 to 31",
      );
    }
    setErrors(next);
    if (next.run_day || next.retirement_age || next.working_days_per_month)
      return;

    save.mutate(
      {
        run_day: Number(form.run_day),
        fiscal_year_start_month: form.fiscal_year_start_month,
        pagumen_proration_strategy: form.pagumen_proration_strategy,
        retirement_age: Number(form.retirement_age),
        // Sent only when the person changed it: saving any other field must
        // not pin the organisation to today's platform value, which the
        // accountant may still correct.
        ...(form.working_days_per_month !==
          persisted.working_days_per_month && {
          working_days_per_month: Number(form.working_days_per_month),
        }),
      },
      {
        onSuccess: () => {
          setEdits(null);
          toast.success(
            t("payroll_config.schedule_saved", "Payroll schedule saved"),
          );
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t(
                "payroll_config.schedule_save_failed",
                "Could not save the payroll schedule",
              ),
            ),
          ),
      },
    );
  }

  const isDirty =
    edits !== null &&
    (Object.keys(form) as (keyof ScheduleForm)[]).some(
      (k) => form[k] !== persisted[k],
    );

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle className="text-base flex items-center gap-2">
            <CalendarClock className="h-4 w-4" />{" "}
            {t("payroll_config.schedule", "Payroll schedule")}
          </CardTitle>
          <p className="mt-1 text-sm text-muted-foreground">
            {t(
              "payroll_config.schedule_desc",
              "When each payroll run happens.",
            )}
          </p>
        </div>
        {isDirty && !isError && (
          <Button onClick={saveSchedule} disabled={save.isPending} size="sm">
            {save.isPending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Save className="mr-2 h-4 w-4" />
            )}
            {t("common.save", "Save")}
          </Button>
        )}
      </CardHeader>

      <CardContent className="grid gap-4 sm:grid-cols-2">
        {isLoading ? (
          <>
            <Skeleton className="h-16" />
            <Skeleton className="h-16" />
          </>
        ) : isError ? (
          // Never show the form over a failed load: its defaults would be
          // saved over the organisation's real schedule.
          <ErrorState
            compact
            className="sm:col-span-2"
            onRetry={() => refetch()}
          />
        ) : (
          <>
            {/* Read-only: the pay period is set when the organisation is
                created. A disabled input showed the raw enum ("monthly")
                and told people to contact support. */}
            <div>
              <p className="text-sm font-medium leading-none">
                {t("payroll_config.pay_period", "Pay period")}
              </p>
              <p className="mt-2 text-sm text-foreground">
                {PAY_PERIOD_LABEL[payPeriod]
                  ? t(
                      `payroll_config.pay_period_${payPeriod}`,
                      PAY_PERIOD_LABEL[payPeriod],
                    )
                  : payPeriod}
              </p>
              {PAY_PERIOD_HELP[payPeriod] && (
                <p className="mt-1 text-xs text-muted-foreground">
                  {t(
                    `payroll_config.pay_period_help_${payPeriod}`,
                    PAY_PERIOD_HELP[payPeriod],
                  )}
                </p>
              )}
            </div>
            <FormField
              id="run_day"
              label={t("payroll_config.run_day", "Payroll run day")}
              required
              hint={t(
                "payroll_config.run_day_help",
                "The day of the month payroll runs. 1 to 28, so it exists in every month.",
              )}
              error={errors.run_day}
            >
              <Input
                type="number"
                inputMode="numeric"
                min={RUN_DAY.min}
                max={RUN_DAY.max}
                value={form.run_day}
                onChange={(e) => update("run_day", e.target.value)}
                className="mt-1"
              />
            </FormField>
            <FormField
              id="fiscal_year_start_month"
              label={t(
                "payroll_config.fiscal_year_start",
                "Fiscal year starts",
              )}
              hint={t(
                "payroll_config.fiscal_year_start_help",
                "Meskerem for most private organisations; Hamle for government.",
              )}
            >
              {(control) => (
                <Select
                  value={String(form.fiscal_year_start_month)}
                  onValueChange={(v) =>
                    update("fiscal_year_start_month", Number(v))
                  }
                >
                  <SelectTrigger {...control} className="mt-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {MONTHS.map((m) => (
                      <SelectItem key={m} value={String(m)}>
                        {ethiopianMonthName(m, locale)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            </FormField>
            <FormField
              id="pagumen_proration_strategy"
              label={t("payroll_config.pagumen", "Pagume pay")}
              hint={t(
                "payroll_config.pagumen_help",
                "How the 5 or 6 days of Pagume are paid.",
              )}
            >
              {(control) => (
                <Select
                  value={form.pagumen_proration_strategy}
                  onValueChange={(v) =>
                    update(
                      "pagumen_proration_strategy",
                      v === "daily_rate" ? "daily_rate" : "full_month",
                    )
                  }
                >
                  <SelectTrigger {...control} className="mt-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="full_month">
                      {t("payroll_config.pagumen_full_month", "A full month")}
                    </SelectItem>
                    <SelectItem value="daily_rate">
                      {t(
                        "payroll_config.pagumen_daily_rate",
                        "Its days at the daily rate",
                      )}
                    </SelectItem>
                  </SelectContent>
                </Select>
              )}
            </FormField>
            <FormField
              id="working_days_per_month"
              label={t(
                "payroll_config.working_days_per_month",
                "Working days per month",
              )}
              required
              hint={t(
                "payroll_config.working_days_per_month_help",
                "Monthly salary is divided by this for a day of unpaid leave, an hour of overtime and sick-leave pay. 22 for a five-day week, 26 for a six-day week.",
              )}
              error={errors.working_days_per_month}
            >
              <Input
                type="number"
                inputMode="numeric"
                min={WORKING_DAYS.min}
                max={WORKING_DAYS.max}
                value={form.working_days_per_month}
                onChange={(e) =>
                  update("working_days_per_month", e.target.value)
                }
                className="mt-1"
              />
            </FormField>
            <FormField
              id="retirement_age"
              label={t("payroll_config.retirement_age", "Retirement age")}
              required
              hint={t(
                "payroll_config.retirement_age_help",
                "Retirement cases are dated from this age.",
              )}
              error={errors.retirement_age}
            >
              <Input
                type="number"
                inputMode="numeric"
                min={RETIREMENT_AGE.min}
                max={RETIREMENT_AGE.max}
                value={form.retirement_age}
                onChange={(e) => update("retirement_age", e.target.value)}
                className="mt-1"
              />
            </FormField>
          </>
        )}
      </CardContent>
    </Card>
  );
}
