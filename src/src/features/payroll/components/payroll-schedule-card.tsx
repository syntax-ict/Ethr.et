"use client";

import { useState } from "react";
import { CalendarClock, Loader2, Save } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { useSettings, useUpdateSettings } from "@/features/settings/api";
import { ethiopianMonthName } from "@/lib/calendar/ethiopian";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

interface ScheduleForm {
  run_day: number;
  fiscal_year_start_month: number;
  pagumen_proration_strategy: "full_month" | "daily_rate";
  retirement_age: number;
}

const MONTHS = Array.from({ length: 13 }, (_, i) => i + 1);

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

  const { data, isLoading } = useSettings();

  const persisted: ScheduleForm = {
    run_day: data?.payroll?.run_day ?? 25,
    fiscal_year_start_month: data?.payroll?.fiscal_year_start_month ?? 1,
    pagumen_proration_strategy:
      data?.payroll?.pagumen_proration_strategy === "daily_rate"
        ? "daily_rate"
        : "full_month",
    retirement_age: data?.payroll?.retirement_age ?? 60,
  };
  const payPeriod = data?.payroll?.pay_period ?? "monthly";

  // Until the user edits, the form is the server's values; adopting them this
  // way needs no effect and never clobbers an edit in progress.
  const [edits, setEdits] = useState<ScheduleForm | null>(null);
  const form = edits ?? persisted;

  function update<K extends keyof ScheduleForm>(
    key: K,
    value: ScheduleForm[K],
  ) {
    setEdits({ ...form, [key]: value });
  }

  const save = useUpdateSettings();

  function saveSchedule() {
    save.mutate(form, {
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
            t("payroll_config.schedule_save_failed", "Failed to save schedule"),
          ),
        ),
    });
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
            {t("payroll_config.schedule", "Payroll Schedule")}
          </CardTitle>
          <p className="mt-1 text-sm text-muted-foreground">
            {t(
              "payroll_config.schedule_desc",
              "When each payroll run happens.",
            )}
          </p>
        </div>
        {isDirty && (
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
        ) : (
          <>
            <div>
              <Label htmlFor="pay_period">
                {t("payroll_config.pay_period", "Pay Period")}
              </Label>
              <Input
                id="pay_period"
                value={payPeriod}
                disabled
                className="mt-1"
              />
              <p className="mt-1 text-xs text-muted-foreground">
                {t(
                  "payroll_config.pay_period_help",
                  "Contact support to change the pay period.",
                )}
              </p>
            </div>
            <div>
              <Label htmlFor="run_day">
                {t("payroll_config.run_day", "Payroll Run Day")}
              </Label>
              <Input
                id="run_day"
                type="number"
                min={1}
                max={28}
                value={form.run_day}
                onChange={(e) =>
                  update("run_day", parseInt(e.target.value) || 1)
                }
                className="mt-1"
              />
              <p className="mt-1 text-xs text-muted-foreground">
                {t(
                  "payroll_config.run_day_help",
                  "Day of month to run payroll",
                )}
              </p>
            </div>
            <div>
              <Label htmlFor="fiscal_year_start_month">
                {t("payroll_config.fiscal_year_start", "Fiscal year starts")}
              </Label>
              <Select
                value={String(form.fiscal_year_start_month)}
                onValueChange={(v) =>
                  update("fiscal_year_start_month", Number(v))
                }
              >
                <SelectTrigger id="fiscal_year_start_month" className="mt-1">
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
              <p className="mt-1 text-xs text-muted-foreground">
                {t(
                  "payroll_config.fiscal_year_start_help",
                  "Meskerem for most private organisations; Hamle for government.",
                )}
              </p>
            </div>
            <div>
              <Label htmlFor="pagumen_proration_strategy">
                {t("payroll_config.pagumen", "Pagume pay")}
              </Label>
              <Select
                value={form.pagumen_proration_strategy}
                onValueChange={(v) =>
                  update(
                    "pagumen_proration_strategy",
                    v === "daily_rate" ? "daily_rate" : "full_month",
                  )
                }
              >
                <SelectTrigger id="pagumen_proration_strategy" className="mt-1">
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
              <p className="mt-1 text-xs text-muted-foreground">
                {t(
                  "payroll_config.pagumen_help",
                  "How the 5 or 6 days of Pagume are paid.",
                )}
              </p>
            </div>
            <div>
              <Label htmlFor="retirement_age">
                {t("payroll_config.retirement_age", "Retirement age")}
              </Label>
              <Input
                id="retirement_age"
                type="number"
                min={45}
                max={75}
                value={form.retirement_age}
                onChange={(e) =>
                  update("retirement_age", parseInt(e.target.value) || 60)
                }
                className="mt-1"
              />
              <p className="mt-1 text-xs text-muted-foreground">
                {t(
                  "payroll_config.retirement_age_help",
                  "Retirement cases are dated from this age.",
                )}
              </p>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  );
}
