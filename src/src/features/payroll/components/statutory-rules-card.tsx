"use client";

import { Lock, RotateCcw, Scale } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { apiErrorMessage } from "@/lib/api/error-message";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import {
  type StatutoryRule,
  useResetStatutoryRule,
  useStatutoryRules,
} from "../api";

type T = (
  key: string,
  fallback?: string,
  params?: Record<string, string | number>,
) => string;

const GROUPS = ["payroll", "overtime", "pension", "calendar", "leave"] as const;
type Group = (typeof GROUPS)[number];

function groupOf(code: string): Group {
  if (code.startsWith("overtime.")) return "overtime";
  if (code.startsWith("pension.")) return "pension";
  if (code.startsWith("leave.")) return "leave";
  if (code.startsWith("fiscal_year.") || code.startsWith("pagume.")) {
    return "calendar";
  }
  return "payroll";
}

/** Literal keys, so the i18n gate checks each title exists in every locale. */
function groupTitle(group: Group, t: T): string {
  switch (group) {
    case "payroll":
      return t("statutory_rules.group_payroll", "Pay and deductions");
    case "overtime":
      return t("statutory_rules.group_overtime", "Overtime");
    case "pension":
      return t("statutory_rules.group_pension", "Pension");
    case "calendar":
      return t("statutory_rules.group_calendar", "Fiscal year and Pagume");
    case "leave":
      return t("statutory_rules.group_leave", "Leave");
  }
}

function ruleTitle(code: string, t: T): string {
  switch (code) {
    case "payroll.working_days_per_month":
      return t(
        "statutory_rules.working_days_per_month",
        "Working days in a month",
      );
    case "payroll.hours_per_day":
      return t("statutory_rules.hours_per_day", "Working hours in a day");
    case "payroll.deduction_cap_ratio":
      return t(
        "statutory_rules.deduction_cap_ratio",
        "Most that may be deducted from a month's wage",
      );
    case "overtime.minimum_rates":
      return t(
        "statutory_rules.overtime_minimum_rates",
        "Lowest overtime rates",
      );
    case "overtime.night_window":
      return t("statutory_rules.overtime_night_window", "Night hours");
    case "overtime.legal_daily_minutes":
      return t(
        "statutory_rules.overtime_daily_minutes",
        "Overtime limit in a day (minutes)",
      );
    case "overtime.legal_weekly_minutes":
      return t(
        "statutory_rules.overtime_weekly_minutes",
        "Overtime limit in a week (minutes)",
      );
    case "overtime.limited_types":
      return t(
        "statutory_rules.overtime_limited_types",
        "Overtime the limits count",
      );
    case "pension.schemes":
      return t("statutory_rules.pension_schemes", "Pension schemes");
    case "pension.default_scheme":
      return t("statutory_rules.pension_default_scheme", "Your pension scheme");
    case "pension.applies_to":
      return t(
        "statutory_rules.pension_applies_to",
        "Contracts pension covers",
      );
    case "fiscal_year.start_month":
      return t(
        "statutory_rules.fiscal_year_start_month",
        "First month of the fiscal year",
      );
    case "pagume.proration":
      return t("statutory_rules.pagume_proration", "How Pagume is paid");
    case "leave.annual.base_days":
      return t(
        "statutory_rules.leave_annual_days",
        "Annual leave in the first year (working days)",
      );
    case "leave.annual.seniority":
      return t(
        "statutory_rules.leave_annual_seniority",
        "Extra annual leave with service",
      );
    case "leave.maternity.days":
      return t(
        "statutory_rules.leave_maternity_days",
        "Maternity leave (days)",
      );
    case "leave.paternity.days":
      return t(
        "statutory_rules.leave_paternity_days",
        "Paternity leave (days)",
      );
    case "leave.sick.tiers":
      return t("statutory_rules.leave_sick_tiers", "Sick leave pay (months)");
    case "leave.sick.window_months":
      return t(
        "statutory_rules.leave_sick_window",
        "Sick leave counted within (months)",
      );
    case "leave.sick.half_pay_ratio":
      return t(
        "statutory_rules.leave_sick_half_pay",
        "Pay kept on a half-pay sick day",
      );
    default:
      return code;
  }
}

function layerText(layer: string, t: T): string {
  switch (layer) {
    case "tenant":
      return t("statutory_rules.layer_tenant", "Your organisation's own value");
    case "sector":
      return t("statutory_rules.layer_sector", "Set for your sector");
    case "organization_type":
      return t(
        "statutory_rules.layer_type",
        "Set for your kind of organisation",
      );
    case "parent_type":
      return t(
        "statutory_rules.layer_parent",
        "Set for the group your kind of organisation belongs to",
      );
    default:
      return t(
        "statutory_rules.layer_baseline",
        "The law, for every organisation",
      );
  }
}

/** A value in words: a number, a word, a fraction, or a short list. */
function formatValue(value: unknown): string {
  if (value === null || value === undefined) return "—";
  if (typeof value !== "object") return String(value);
  if (Array.isArray(value)) return value.map(String).join(", ");

  const map = value as Record<string, unknown>;
  if ("numerator" in map && "denominator" in map) {
    return `${String(map.numerator)}/${String(map.denominator)}`;
  }
  if ("start_hour" in map && "end_hour" in map) {
    const hh = (h: unknown) => `${String(h).padStart(2, "0")}:00`;
    return `${hh(map.start_hour)}–${hh(map.end_hour)}`;
  }

  return Object.entries(map)
    .filter(([, v]) => v !== false)
    .map(([k, v]) => (v === true ? k : `${k} ${String(v)}`))
    .map((part) => part.replaceAll("_", " "))
    .join(" · ");
}

/**
 * Settings → Payroll → Statutory rules: every rule payroll applies today and
 * where it came from. The law's rules are locked and say why; a rule the
 * organisation may change is changed on the payroll setup tab and can be put
 * back to its default here, which deletes the organisation's own value.
 */
export function StatutoryRulesCard({ onEdit }: { onEdit?: () => void }) {
  const { t } = useT();
  const query = useStatutoryRules();
  const reset = useResetStatutoryRule();
  const canManage = usePermissions().hasPermission("payroll.manageConfig");

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Scale className="h-4 w-4" aria-hidden="true" />
          {t("statutory_rules.title", "Statutory rules")}
        </CardTitle>
        <p className="mt-1 text-sm text-muted-foreground">
          {t(
            "statutory_rules.description",
            "What payroll applies today, and where each rule comes from. Rules the law sets are locked; the rest follow your kind of organisation unless you change them.",
          )}
        </p>
      </CardHeader>
      <CardContent>
        <QueryBoundary
          query={query}
          loading={<Skeleton className="h-48 w-full" />}
          isEmpty={(data) => data.data.length === 0}
        >
          {(data) => (
            <div className="space-y-6">
              {GROUPS.map((group) => {
                const rules = data.data.filter(
                  (r) => groupOf(r.code) === group,
                );
                if (rules.length === 0) return null;
                const headingId = `statutory-group-${group}`;

                return (
                  <section key={group} aria-labelledby={headingId}>
                    <h3 id={headingId} className="mb-2 text-sm font-semibold">
                      {groupTitle(group, t)}
                    </h3>
                    <ul className="divide-y rounded-lg border">
                      {rules.map((rule) => (
                        <RuleRow
                          key={rule.code}
                          rule={rule}
                          canManage={canManage}
                          resetting={
                            reset.isPending && reset.variables === rule.code
                          }
                          onEdit={onEdit}
                          onReset={() =>
                            reset.mutate(rule.code, {
                              onSuccess: () =>
                                toast.success(
                                  t(
                                    "statutory_rules.reset_done",
                                    "Back to the default",
                                  ),
                                ),
                              onError: (err) =>
                                toast.error(
                                  apiErrorMessage(
                                    err,
                                    t(
                                      "statutory_rules.reset_failed",
                                      "Could not reset this rule",
                                    ),
                                  ),
                                ),
                            })
                          }
                        />
                      ))}
                    </ul>
                  </section>
                );
              })}
            </div>
          )}
        </QueryBoundary>
      </CardContent>
    </Card>
  );
}

function RuleRow({
  rule,
  canManage,
  resetting,
  onEdit,
  onReset,
}: {
  rule: StatutoryRule;
  canManage: boolean;
  resetting: boolean;
  onEdit?: () => void;
  onReset: () => void;
}) {
  const { t } = useT();
  const locked = rule.enforcement === "locked";

  return (
    <li className="flex flex-col gap-2 p-3 sm:flex-row sm:items-start sm:justify-between">
      <div className="min-w-0 space-y-1">
        <p className="text-sm font-medium">{ruleTitle(rule.code, t)}</p>
        <p className="text-xs text-muted-foreground">
          {layerText(rule.layer, t)}
          {rule.legal_reference ? ` · ${rule.legal_reference}` : ""}
          {rule.version
            ? ` · ${t("statutory_rules.version", "version :version", { version: rule.version })}`
            : ""}
        </p>
        {locked && rule.floor_direction === "min" && (
          <p className="text-xs text-muted-foreground">
            {t(
              "statutory_rules.floor_min",
              "The least the law allows: you may give more on the overtime card or a leave type, never less.",
            )}
          </p>
        )}
        {locked && rule.floor_direction === "max" && (
          <p className="text-xs text-muted-foreground">
            {t(
              "statutory_rules.floor_max",
              "The most the law allows: you may stay below it, never above.",
            )}
          </p>
        )}
        {rule.overridden && (
          <p className="text-xs text-muted-foreground">
            {t("statutory_rules.default_was", "Default: :value", {
              value: formatValue(rule.default_value),
            })}
          </p>
        )}
      </div>
      <div className="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
        <span className="font-mono text-sm">{formatValue(rule.value)}</span>
        {locked ? (
          <Badge variant="outline" className="gap-1">
            <Lock className="h-3 w-3" aria-hidden="true" />
            {t("statutory_rules.locked", "Set by law")}
          </Badge>
        ) : rule.overridden ? (
          <Badge variant="info">
            {t("statutory_rules.yours", "Your value")}
          </Badge>
        ) : (
          <Badge variant="neutral">
            {t("statutory_rules.default", "Default")}
          </Badge>
        )}
        {!rule.confirmed && (
          <Badge variant="warning">
            {t("statutory_rules.unconfirmed", "Awaiting accountant sign-off")}
          </Badge>
        )}
        {rule.editable && canManage && onEdit && (
          <Button type="button" size="sm" variant="ghost" onClick={onEdit}>
            {t("statutory_rules.change", "Change")}
          </Button>
        )}
        {rule.overridden && rule.editable && canManage && (
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={resetting}
            onClick={onReset}
          >
            <RotateCcw className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
            {t("statutory_rules.reset", "Reset to default")}
          </Button>
        )}
      </div>
    </li>
  );
}
