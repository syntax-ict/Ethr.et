"use client";

import { useState } from "react";
import { Landmark, Loader2, Plus } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
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
import { Switch } from "@/components/ui/switch";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { apiErrorMessage } from "@/lib/api/error-message";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { todayIn } from "@/lib/utils/date";
import { etbToCents, formatETB } from "@/lib/utils/currency";
import {
  useCreatePensionRule,
  usePensionRules,
  type CreatePensionRule,
  type PensionRule,
} from "../api";

type Scheme = CreatePensionRule["scheme"];

interface RuleForm {
  scheme: Scheme;
  employeeRate: string;
  employerRate: string;
  ceilingEtb: string;
  base: "basic" | "regular_salary";
  exemptNonNationals: boolean;
  minServiceDays: string;
  legalReference: string;
  effectiveFrom: string;
}

type RuleErrors = Partial<
  Record<
    "employeeRate" | "employerRate" | "legalReference" | "effectiveFrom",
    string
  >
>;

/** An empty form, dated today where the organisation is. */
const emptyForm = (timeZone: string): RuleForm => ({
  scheme: "private",
  employeeRate: "",
  employerRate: "",
  ceilingEtb: "",
  base: "basic",
  exemptNonNationals: true,
  minServiceDays: "",
  legalReference: "",
  effectiveFrom: todayIn(timeZone),
});

function isPercent(value: string): boolean {
  if (!/^\d+(\.\d{1,2})?$/.test(value.trim())) return false;
  const n = Number(value);
  return n >= 0 && n <= 100;
}

/**
 * Pension contribution rules, dated and with the law they come from — beside
 * Tax Brackets, because they are the same kind of thing. The platform's rules
 * are the law as researched (docs/payroll/STATUTORY-RULES-RESEARCH.md); an
 * organisation may add its own from a date, which replaces the platform's for
 * its scheme from then on. A run already processed keeps the rule it used.
 */
export function PensionRulesCard() {
  const { t } = useT();
  const { formatDate, timeZone } = useDateFormatters();
  const query = usePensionRules();
  const create = useCreatePensionRule();
  const canManage = usePermissions().hasPermission("payroll.manageConfig");
  const [adding, setAdding] = useState(false);
  const [form, setForm] = useState<RuleForm>(() => emptyForm(timeZone));
  const [errors, setErrors] = useState<RuleErrors>({});
  const [summary, setSummary] = useState<string | null>(null);

  const schemeLabel = (scheme: string) =>
    ({
      private: t("pension_rules.scheme_private"),
      public: t("pension_rules.scheme_public"),
      public_military: t("pension_rules.scheme_public_military"),
    })[scheme] ?? scheme;

  function set<K extends keyof RuleForm>(key: K, value: RuleForm[K]) {
    setForm((f) => ({ ...f, [key]: value }));
    if (key in errors) {
      setErrors((prev) => ({ ...prev, [key]: undefined }));
    }
  }

  function closeForm() {
    setAdding(false);
    setForm(emptyForm(timeZone));
    setErrors({});
    setSummary(null);
  }

  // Validated on submit with the reason beside each field. The Save button
  // used to sit disabled with nothing marked required and nothing saying
  // which of the four fields was still missing.
  function submit() {
    const next: RuleErrors = {};
    if (!isPercent(form.employeeRate)) {
      next.employeeRate = t(
        "pension_rules.percent_required",
        "Enter a percentage from 0 to 100",
      );
    }
    if (!isPercent(form.employerRate)) {
      next.employerRate = t(
        "pension_rules.percent_required",
        "Enter a percentage from 0 to 100",
      );
    }
    if (form.legalReference.trim().length < 3) {
      next.legalReference = t(
        "pension_rules.law_missing",
        "Name the proclamation or directive this rate comes from",
      );
    }
    if (form.effectiveFrom === "") {
      next.effectiveFrom = t("validation.required", "This field is required");
    }
    setErrors(next);
    const invalid = Object.values(next).some(Boolean);
    setSummary(
      invalid
        ? t(
            "pension_rules.check_fields",
            "Check the highlighted fields before saving.",
          )
        : null,
    );
    if (invalid) return;

    create.mutate(
      {
        scheme: form.scheme,
        employee_rate: Number(form.employeeRate),
        employer_rate: Number(form.employerRate),
        ceiling_cents:
          form.ceilingEtb.trim() === ""
            ? null
            : etbToCents(Number(form.ceilingEtb)),
        base: form.base,
        exempt_non_nationals: form.exemptNonNationals,
        min_service_days:
          form.minServiceDays.trim() === ""
            ? null
            : Number(form.minServiceDays),
        legal_reference: form.legalReference.trim(),
        effective_from: form.effectiveFrom,
      },
      {
        onSuccess: () => {
          toast.success(t("pension_rules.saved"));
          closeForm();
        },
        onError: (err) =>
          toast.error(apiErrorMessage(err, t("pension_rules.save_failed"))),
      },
    );
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle className="flex items-center gap-2 text-base">
            <Landmark className="h-4 w-4" /> {t("pension_rules.title")}
          </CardTitle>
          <p className="mt-1 text-sm text-muted-foreground">
            {t("pension_rules.description")}
          </p>
        </div>
        {canManage && !adding && (
          <Button size="sm" variant="outline" onClick={() => setAdding(true)}>
            <Plus className="mr-1.5 h-4 w-4" />
            {t("pension_rules.add")}
          </Button>
        )}
      </CardHeader>
      <CardContent className="space-y-4">
        <QueryBoundary
          query={query}
          loading={<Skeleton className="h-32 w-full" />}
          isEmpty={(data) => data.data.length === 0}
          empty={
            <EmptyState
              icon={Landmark}
              title={t("pension_rules.empty_title", "No pension rules")}
              description={t("pension_rules.empty")}
            />
          }
        >
          {(data) => (
            <>
              <p className="rounded-lg border bg-muted/30 p-3 text-sm">
                {t("pension_rules.in_force", undefined, {
                  scheme: schemeLabel(data.in_force.scheme),
                  employee: `${data.in_force.employee_rate}%`,
                  employer: `${data.in_force.employer_rate}%`,
                  law: String(data.in_force.legal_reference),
                })}
              </p>
              <SimpleTable
                mobileCards
                caption={t("pension_rules.title")}
                headers={[
                  t("pension_rules.scheme"),
                  t("pension_rules.employee"),
                  t("pension_rules.employer"),
                  t("pension_rules.ceiling"),
                  t("pension_rules.from"),
                  t("pension_rules.to"),
                  t("pension_rules.law"),
                ]}
                colClassName={[
                  "",
                  "",
                  "",
                  "hidden md:table-cell",
                  "",
                  "hidden sm:table-cell",
                  "",
                ]}
                rows={data.data.map((rule: PensionRule) => ({
                  key: rule.public_id,
                  cells: [
                    <span key="s">{schemeLabel(rule.scheme)}</span>,
                    <span key="e" className="tabular-nums">
                      {rule.employee_rate}%
                    </span>,
                    <span key="r" className="tabular-nums">
                      {rule.employer_rate}%
                    </span>,
                    <span key="c" className="text-muted-foreground">
                      {rule.ceiling_cents === null
                        ? t("pension_rules.no_ceiling")
                        : formatETB(rule.ceiling_cents)}
                    </span>,
                    <span key="f" className="tabular-nums">
                      {rule.effective_from
                        ? formatDate(rule.effective_from)
                        : t("common.not_set", "Not set")}
                    </span>,
                    <span
                      key="t"
                      className="tabular-nums text-muted-foreground"
                    >
                      {rule.effective_to
                        ? formatDate(rule.effective_to)
                        : t("pension_rules.open_ended", "Still in force")}
                    </span>,
                    <div key="l" className="space-y-1">
                      <span className="text-sm">{rule.legal_reference}</span>
                      <Badge variant="outline" className="ml-2 text-xs">
                        {rule.owner === "platform"
                          ? t("pension_rules.owner_platform")
                          : t("pension_rules.owner_organisation")}
                      </Badge>
                    </div>,
                  ],
                }))}
              />
            </>
          )}
        </QueryBoundary>

        {adding && (
          <form
            className="space-y-4 rounded-lg border p-4"
            noValidate
            onSubmit={(e) => {
              e.preventDefault();
              submit();
            }}
          >
            <FormErrorSummary message={summary} />
            <div className="grid gap-4 sm:grid-cols-3">
              <FormField id="pr-scheme" label={t("pension_rules.scheme")}>
                {(control) => (
                  <Select
                    value={form.scheme}
                    onValueChange={(v) => set("scheme", v as Scheme)}
                  >
                    <SelectTrigger {...control} className="mt-1">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {(["private", "public", "public_military"] as const).map(
                        (s) => (
                          <SelectItem key={s} value={s}>
                            {schemeLabel(s)}
                          </SelectItem>
                        ),
                      )}
                    </SelectContent>
                  </Select>
                )}
              </FormField>
              <FormField
                id="pr-employee"
                label={t("pension_rules.employee")}
                required
                error={errors.employeeRate}
              >
                <Input
                  type="number"
                  min={0}
                  max={100}
                  step="0.01"
                  value={form.employeeRate}
                  onChange={(e) => set("employeeRate", e.target.value)}
                  className="mt-1"
                />
              </FormField>
              <FormField
                id="pr-employer"
                label={t("pension_rules.employer")}
                required
                error={errors.employerRate}
              >
                <Input
                  type="number"
                  min={0}
                  max={100}
                  step="0.01"
                  value={form.employerRate}
                  onChange={(e) => set("employerRate", e.target.value)}
                  className="mt-1"
                />
              </FormField>
              <FormField id="pr-ceiling" label={t("pension_rules.ceiling_etb")}>
                <Input
                  type="number"
                  min={0}
                  step="0.01"
                  value={form.ceilingEtb}
                  onChange={(e) => set("ceilingEtb", e.target.value)}
                  placeholder={t("pension_rules.no_ceiling")}
                  className="mt-1"
                />
              </FormField>
              <FormField id="pr-base" label={t("pension_rules.base")}>
                {(control) => (
                  <Select
                    value={form.base}
                    onValueChange={(v) =>
                      set(
                        "base",
                        v === "regular_salary" ? "regular_salary" : "basic",
                      )
                    }
                  >
                    <SelectTrigger {...control} className="mt-1">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="basic">
                        {t("pension_rules.base_basic")}
                      </SelectItem>
                      <SelectItem value="regular_salary">
                        {t("pension_rules.base_regular")}
                      </SelectItem>
                    </SelectContent>
                  </Select>
                )}
              </FormField>
              <FormField
                id="pr-min-days"
                label={t("pension_rules.min_service_days")}
              >
                <Input
                  type="number"
                  min={1}
                  max={366}
                  value={form.minServiceDays}
                  onChange={(e) => set("minServiceDays", e.target.value)}
                  placeholder={t("pension_rules.none")}
                  className="mt-1"
                />
              </FormField>
            </div>
            <div className="flex items-center justify-between gap-3 rounded-lg border p-3">
              <Label htmlFor="pr-exempt" className="font-normal">
                {t("pension_rules.exempt_non_nationals")}
              </Label>
              <Switch
                id="pr-exempt"
                checked={form.exemptNonNationals}
                onCheckedChange={(v) => set("exemptNonNationals", v)}
              />
            </div>
            <div className="grid gap-4 sm:grid-cols-[2fr_1fr]">
              <FormField
                id="pr-law"
                label={t("pension_rules.law_required")}
                required
                error={errors.legalReference}
              >
                <Input
                  value={form.legalReference}
                  maxLength={200}
                  onChange={(e) => set("legalReference", e.target.value)}
                  placeholder={t(
                    "pension_rules.law_placeholder",
                    "Proclamation 1268/2022, Article 10",
                  )}
                  className="mt-1"
                />
              </FormField>
              <FormField
                id="pr-from"
                label={t("pension_rules.from")}
                required
                error={errors.effectiveFrom}
              >
                {(control) => (
                  <DualCalendarDateInput
                    {...control}
                    value={form.effectiveFrom}
                    onChange={(v) => set("effectiveFrom", v)}
                  />
                )}
              </FormField>
            </div>
            <p className="text-xs text-muted-foreground">
              {t("pension_rules.add_hint")}
            </p>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={closeForm}>
                {t("common.cancel")}
              </Button>
              <Button type="submit" disabled={create.isPending}>
                {create.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("pension_rules.save")}
              </Button>
            </div>
          </form>
        )}
      </CardContent>
    </Card>
  );
}
