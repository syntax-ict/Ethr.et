"use client";

import { useState } from "react";
import { Landmark, Loader2, Save } from "lucide-react";
import { toast } from "sonner";
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
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";
import { ErrorState } from "@/components/shared/error-state";

interface StatutoryForm {
  employer_tin: string;
  tax_center: string;
  pension_scheme: "private" | "public" | "public_military";
  pension_registration_number: string;
  payout_method: "" | "bank" | "cash";
  employer_bank_name: string;
}

/**
 * Who the organisation is to the tax office and the pension agency: the
 * header of every statutory schedule a payroll run exports. Without the
 * employer TIN the schedules still download, but say NOT SET where it goes.
 */
export function StatutoryDetailsCard() {
  const { t } = useT();
  const { data, isLoading, isError, refetch } = useSettings();
  const save = useUpdateSettings();

  const persisted: StatutoryForm = {
    employer_tin: data?.payroll?.employer_tin ?? "",
    tax_center: data?.payroll?.tax_center ?? "",
    pension_scheme:
      data?.payroll?.pension_scheme === "public" ||
      data?.payroll?.pension_scheme === "public_military"
        ? data.payroll.pension_scheme
        : "private",
    pension_registration_number:
      data?.payroll?.pension_registration_number ?? "",
    payout_method:
      data?.payroll?.payout_method === "bank" ||
      data?.payroll?.payout_method === "cash"
        ? data.payroll.payout_method
        : "",
    employer_bank_name: data?.payroll?.employer_bank_name ?? "",
  };
  const [edits, setEdits] = useState<StatutoryForm | null>(null);
  const form = edits ?? persisted;
  const isDirty =
    edits !== null &&
    (Object.keys(form) as (keyof StatutoryForm)[]).some(
      (k) => form[k] !== persisted[k],
    );

  function update<K extends keyof StatutoryForm>(
    key: K,
    value: StatutoryForm[K],
  ) {
    setEdits({ ...form, [key]: value });
  }

  function submit() {
    save.mutate(
      {
        employer_tin: form.employer_tin.trim() || null,
        tax_center: form.tax_center.trim() || null,
        // Only when changed: saving the scheme makes it the organisation's
        // own choice over its type's default (Statutory rules).
        ...(form.pension_scheme !== persisted.pension_scheme
          ? { pension_scheme: form.pension_scheme }
          : {}),
        pension_registration_number:
          form.pension_registration_number.trim() || null,
        payout_method: form.payout_method || null,
        employer_bank_name:
          form.payout_method === "bank"
            ? form.employer_bank_name.trim() || null
            : null,
      },
      {
        onSuccess: () => {
          setEdits(null);
          toast.success(t("statutory.saved"));
        },
        onError: (err) =>
          toast.error(apiErrorMessage(err, t("statutory.save_failed"))),
      },
    );
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle className="flex items-center gap-2 text-base">
            <Landmark className="h-4 w-4" /> {t("statutory.card_title")}
          </CardTitle>
          <p className="mt-1 text-sm text-muted-foreground">
            {t("statutory.card_desc")}
          </p>
        </div>
        {isDirty && !isError && (
          <Button onClick={submit} disabled={save.isPending} size="sm">
            {save.isPending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Save className="mr-2 h-4 w-4" />
            )}
            {t("common.save")}
          </Button>
        )}
      </CardHeader>
      <CardContent className="grid gap-4 sm:grid-cols-3">
        {isLoading ? (
          <>
            <Skeleton className="h-16" />
            <Skeleton className="h-16" />
            <Skeleton className="h-16" />
          </>
        ) : isError ? (
          // Never show the form over a failed load: its empty defaults would
          // be saved over the organisation's real statutory details.
          <ErrorState
            compact
            className="sm:col-span-3"
            onRetry={() => refetch()}
          />
        ) : (
          <>
            <div>
              <Label htmlFor="employer_tin">
                {t("statutory.employer_tin")}
              </Label>
              <Input
                id="employer_tin"
                inputMode="numeric"
                maxLength={10}
                value={form.employer_tin}
                onChange={(e) =>
                  update("employer_tin", e.target.value.replace(/\D/g, ""))
                }
                placeholder="0000000000"
                className="mt-1 font-mono"
              />
              <p className="mt-1 text-xs text-muted-foreground">
                {t("statutory.employer_tin_hint")}
              </p>
            </div>
            <div>
              <Label htmlFor="tax_center">{t("statutory.tax_center")}</Label>
              <Input
                id="tax_center"
                maxLength={120}
                value={form.tax_center}
                onChange={(e) => update("tax_center", e.target.value)}
                className="mt-1"
              />
            </div>
            <div>
              <Label htmlFor="pension_scheme">
                {t("statutory.pension_scheme")}
              </Label>
              <Select
                value={form.pension_scheme}
                onValueChange={(v) =>
                  update(
                    "pension_scheme",
                    v === "public" || v === "public_military" ? v : "private",
                  )
                }
              >
                <SelectTrigger
                  id="pension_scheme"
                  className="mt-1"
                  aria-describedby="pension_scheme-hint"
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="private">
                    {t("statutory.scheme_private")}
                  </SelectItem>
                  <SelectItem value="public">
                    {t("statutory.scheme_public")}
                  </SelectItem>
                  <SelectItem value="public_military">
                    {t("statutory.scheme_public_military")}
                  </SelectItem>
                </SelectContent>
              </Select>
              <p
                id="pension_scheme-hint"
                className="mt-1 text-xs text-muted-foreground"
              >
                {t(
                  "statutory.pension_scheme_hint",
                  "Payroll uses the pension rule for this scheme from the card below.",
                )}
              </p>
            </div>
            <div>
              <Label htmlFor="pension_registration_number">
                {t(
                  "statutory.pension_registration_number",
                  "Pension registration number",
                )}
              </Label>
              <Input
                id="pension_registration_number"
                maxLength={40}
                value={form.pension_registration_number}
                onChange={(e) =>
                  update("pension_registration_number", e.target.value)
                }
                aria-describedby="pension_registration_number-hint"
                className="mt-1 font-mono"
              />
              <p
                id="pension_registration_number-hint"
                className="mt-1 text-xs text-muted-foreground"
              >
                {t(
                  "statutory.pension_registration_agency",
                  "Your registration with :agency.",
                  { agency: data?.payroll?.pension_agency ?? "" },
                )}
              </p>
            </div>
            <div>
              <Label htmlFor="payout_method">
                {t("statutory.payout_method", "How salaries are paid")}
              </Label>
              <Select
                value={form.payout_method}
                onValueChange={(v) =>
                  update("payout_method", v === "cash" ? "cash" : "bank")
                }
              >
                <SelectTrigger id="payout_method" className="mt-1">
                  <SelectValue
                    placeholder={t("statutory.payout_choose", "Choose one")}
                  />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="bank">
                    {t("statutory.payout_bank", "Bank transfer")}
                  </SelectItem>
                  <SelectItem value="cash">
                    {t("statutory.payout_cash", "Cash")}
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>
            {form.payout_method === "bank" && (
              <div>
                <Label htmlFor="employer_bank_name">
                  {t("statutory.employer_bank_name", "Bank you pay from")}
                </Label>
                <Input
                  id="employer_bank_name"
                  maxLength={120}
                  value={form.employer_bank_name}
                  onChange={(e) => update("employer_bank_name", e.target.value)}
                  className="mt-1"
                />
              </div>
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}
