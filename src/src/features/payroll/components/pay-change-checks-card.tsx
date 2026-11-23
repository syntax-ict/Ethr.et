"use client";

import { useState } from "react";
import { Loader2, Save, TrendingUp } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { FormField } from "@/components/patterns/FormField";
import { ErrorState } from "@/components/shared/error-state";
import { useSettings, useUpdateSettings } from "@/features/settings/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

const PERCENT = { min: 1, max: 100 };
/** The largest minimum, in whole birr (PayChangeThresholds::MIN_CENTS_MAX). */
const MIN_BIRR_MAX = 1_000_000;

interface ChecksForm {
  percent: string;
  minBirr: string;
}

function wholeNumberIn(value: string, min: number, max: number): boolean {
  if (!/^\d+$/.test(value.trim())) return false;
  const n = Number(value);
  return n >= min && n <= max;
}

/**
 * When a change in someone's net pay is listed on the run before approval
 * (PreApprovalChecks): by at least this percent of the last released
 * payslip, and by at least this amount. Both must hold, so small payslips
 * and small changes are not listed. Nothing is ever blocked by it.
 */
export function PayChangeChecksCard() {
  const { t } = useT();
  const { data, isLoading, isError, refetch } = useSettings();
  const save = useUpdateSettings();

  const persisted: ChecksForm = {
    percent: String(data?.payroll?.pay_change_alert_percent ?? 20),
    minBirr: String(
      Math.round((data?.payroll?.pay_change_alert_min_cents ?? 50_000) / 100),
    ),
  };
  const [edits, setEdits] = useState<ChecksForm | null>(null);
  const [errors, setErrors] = useState<
    Partial<Record<keyof ChecksForm, string>>
  >({});
  const form = edits ?? persisted;
  const isDirty =
    edits !== null &&
    (form.percent !== persisted.percent || form.minBirr !== persisted.minBirr);

  function update(key: keyof ChecksForm, value: string) {
    setEdits({ ...form, [key]: value });
    setErrors((prev) => (prev[key] ? { ...prev, [key]: undefined } : prev));
  }

  function submit() {
    const next: Partial<Record<keyof ChecksForm, string>> = {};
    if (!wholeNumberIn(form.percent, PERCENT.min, PERCENT.max)) {
      next.percent = t(
        "payroll_checks.percent_range",
        "Enter a whole percent from 1 to 100",
      );
    }
    if (!wholeNumberIn(form.minBirr, 0, MIN_BIRR_MAX)) {
      next.minBirr = t(
        "payroll_checks.min_range",
        "Enter a whole amount in birr from 0 to 1,000,000",
      );
    }
    setErrors(next);
    if (next.percent || next.minBirr) return;

    save.mutate(
      {
        pay_change_alert_percent: Number(form.percent),
        pay_change_alert_min_cents: Number(form.minBirr) * 100,
      },
      {
        onSuccess: () => {
          setEdits(null);
          toast.success(
            t("payroll_checks.saved", "Checks before approval saved"),
          );
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t(
                "payroll_checks.save_failed",
                "Could not save the checks before approval",
              ),
            ),
          ),
      },
    );
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle className="flex items-center gap-2 text-base">
            <TrendingUp className="h-4 w-4" aria-hidden="true" />{" "}
            {t("payroll_checks.card_title", "Checks before approval")}
          </CardTitle>
          <p className="mt-1 text-sm text-muted-foreground">
            {t(
              "payroll_checks.card_desc",
              "A run lists each employee whose net pay moved by both of these since their last released payslip. Approval is never blocked by it.",
            )}
          </p>
        </div>
        {isDirty && !isError && (
          <Button onClick={submit} disabled={save.isPending} size="sm">
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
          <ErrorState
            compact
            className="sm:col-span-2"
            onRetry={() => refetch()}
          />
        ) : (
          <>
            <FormField
              id="pay_change_alert_percent"
              label={t(
                "payroll_checks.percent_label",
                "Change of at least (%)",
              )}
              hint={t(
                "payroll_checks.percent_help",
                "Of the employee's last released net pay.",
              )}
              error={errors.percent}
            >
              <Input
                type="number"
                inputMode="numeric"
                min={PERCENT.min}
                max={PERCENT.max}
                value={form.percent}
                onChange={(e) => update("percent", e.target.value)}
                className="mt-1"
              />
            </FormField>
            <FormField
              id="pay_change_alert_min_birr"
              label={t("payroll_checks.min_label", "And of at least (ETB)")}
              hint={t(
                "payroll_checks.min_help",
                "So a large percent of a small payslip is not listed.",
              )}
              error={errors.minBirr}
            >
              <Input
                type="number"
                inputMode="numeric"
                min={0}
                max={MIN_BIRR_MAX}
                value={form.minBirr}
                onChange={(e) => update("minBirr", e.target.value)}
                className="mt-1"
              />
            </FormField>
          </>
        )}
      </CardContent>
    </Card>
  );
}
