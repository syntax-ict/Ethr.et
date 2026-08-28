"use client";

import { useState } from "react";
import { Loader2, RotateCcw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import {
  useOvertimeRates,
  useUpdateOvertimeRates,
  type OvertimeRates,
} from "../api";

type RateKey = keyof OvertimeRates;

/** Statutory minimums — the API rejects anything below these. */
const MINIMUMS: OvertimeRates = {
  normal: 1.25,
  night: 1.5,
  holiday: 2,
  holiday_night: 2.5,
};

const ORDER: RateKey[] = ["normal", "night", "holiday", "holiday_night"];

function apiDetail(err: unknown): string | undefined {
  return (err as { response?: { data?: { detail?: string } } }).response?.data
    ?.detail;
}

export function OvertimeRatesCard() {
  const { t } = useT();
  const query = useOvertimeRates();
  const updateRates = useUpdateOvertimeRates();

  const [form, setForm] = useState<Record<RateKey, string> | null>(null);
  const [errors, setErrors] = useState<Partial<Record<RateKey, string>>>({});
  const [summary, setSummary] = useState<string | null>(null);

  // Seed the inputs once the saved rates arrive, and whenever they change
  // underneath us (another tab, a refetch after save). Adjusting state during
  // render avoids an extra effect commit — see
  // https://react.dev/learn/you-might-not-need-an-effect.
  const rates = query.data?.rates;
  const [prevRates, setPrevRates] = useState(rates);
  if (rates && rates !== prevRates) {
    setPrevRates(rates);
    setForm({
      normal: String(rates.normal),
      night: String(rates.night),
      holiday: String(rates.holiday),
      holiday_night: String(rates.holiday_night),
    });
  }

  const labels: Record<RateKey, string> = {
    normal: t("payroll_config.ot_normal", "Ordinary day"),
    night: t("payroll_config.ot_night", "Night (22:00–06:00)"),
    holiday: t("payroll_config.ot_holiday", "Public holiday"),
    holiday_night: t("payroll_config.ot_holiday_night", "Holiday night"),
  };

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!form) return;

    // `MINIMUMS` are the statutory multipliers, not house preference: a rate
    // below them underpays overtime and is a labour-law problem, not a typo.
    // The `min` attribute alone left that to a browser bubble that is not
    // translated, not styled, and gone the moment the field is touched.
    const next: Partial<Record<RateKey, string>> = {};
    for (const key of ORDER) {
      const value = Number(form[key]);
      if (form[key] === "" || Number.isNaN(value)) {
        next[key] = t("validation.required", "This field is required");
      } else if (value < MINIMUMS[key]) {
        next[key] = t(
          "payroll_config.rate_below_minimum",
          "Cannot be below the statutory minimum of :min×",
          { min: MINIMUMS[key] },
        );
      } else if (value > 10) {
        next[key] = t("payroll_config.rate_above_max", "Cannot be above 10×");
      }
    }

    setErrors(next);
    const invalid = Object.keys(next).length > 0;
    setSummary(
      invalid
        ? t(
            "payroll_config.rates_invalid",
            "Check the highlighted rates before saving.",
          )
        : null,
    );
    if (invalid) return;

    updateRates.mutate(
      {
        normal: Number(form.normal),
        night: Number(form.night),
        holiday: Number(form.holiday),
        holiday_night: Number(form.holiday_night),
      },
      {
        onSuccess: () =>
          toast.success(t("payroll_config.ot_saved", "Overtime rates updated")),
        onError: (err) =>
          toast.error(
            apiDetail(err) ??
              t("payroll_config.ot_save_failed", "Could not save rates"),
          ),
      },
    );
  }

  function resetToDefaults() {
    const defaults = query.data?.defaults;
    if (!defaults) return;

    setForm({
      normal: String(defaults.normal),
      night: String(defaults.night),
      holiday: String(defaults.holiday),
      holiday_night: String(defaults.holiday_night),
    });
  }

  return (
    <Card>
      <CardHeader>
        <div className="flex flex-wrap items-center gap-2">
          <CardTitle className="text-base">
            {t("payroll_config.overtime_rates", "Overtime rates")}
          </CardTitle>
          {query.data && !query.data.is_customized && (
            <Badge variant="outline">
              {t("payroll_config.using_defaults", "Using defaults")}
            </Badge>
          )}
        </div>
        <p className="mt-1 text-sm text-muted-foreground">
          {t(
            "payroll_config.overtime_rates_desc",
            "Multipliers applied to the hourly rate. The Labour Proclamation minimums cannot be reduced.",
          )}
        </p>
      </CardHeader>

      <CardContent>
        <QueryBoundary query={query} isEmpty={() => false}>
          {() =>
            form && (
              <form onSubmit={handleSubmit} className="space-y-4" noValidate>
                <FormErrorSummary message={summary} />

                <div className="grid gap-4 sm:grid-cols-2">
                  {ORDER.map((key) => (
                    <FormField
                      key={key}
                      id={`ot_${key}`}
                      label={labels[key]}
                      required
                      hint={`${t("payroll_config.minimum", "Minimum")} ${
                        MINIMUMS[key]
                      }×`}
                      error={errors[key]}
                    >
                      <Input
                        type="number"
                        step="0.05"
                        min={MINIMUMS[key]}
                        max="10"
                        value={form[key]}
                        onChange={(e) => {
                          const value = e.target.value;
                          setForm((p) => (p ? { ...p, [key]: value } : p));
                          // Clear this field's error on edit; the rest are
                          // independent, so nothing else needs re-checking
                          // until the next submit.
                          setErrors((p) =>
                            p[key] ? { ...p, [key]: undefined } : p,
                          );
                        }}
                        className="mt-1 font-mono"
                      />
                    </FormField>
                  ))}
                </div>

                <div className="flex justify-end gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    onClick={resetToDefaults}
                  >
                    <RotateCcw className="mr-2 h-4 w-4" />
                    {t("payroll_config.reset_defaults", "Reset to defaults")}
                  </Button>
                  <Button type="submit" disabled={updateRates.isPending}>
                    {updateRates.isPending && (
                      <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    {t("common.save", "Save")}
                  </Button>
                </div>
              </form>
            )
          }
        </QueryBoundary>
      </CardContent>
    </Card>
  );
}
