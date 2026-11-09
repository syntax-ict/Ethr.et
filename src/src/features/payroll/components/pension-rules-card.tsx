"use client";

import { Landmark, Lock } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { formatETB } from "@/lib/utils/currency";
import { usePensionRules, type PensionRule } from "../api";

/**
 * Pension contribution rules, dated and with the law they come from, beside
 * Tax Brackets because they are the same kind of thing. The rates are the
 * law's exactly (Proclamations 1268/2022 and 1267/2022): since 2026-11-09 an
 * organisation cannot add its own, and the API refuses one. A rule saved
 * before then is still listed while it is in force, and the readiness
 * checklist flags it. A run already processed keeps the rule it used.
 */
export function PensionRulesCard() {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const query = usePensionRules();

  const schemeLabel = (scheme: string) =>
    ({
      private: t("pension_rules.scheme_private"),
      public: t("pension_rules.scheme_public"),
      public_military: t("pension_rules.scheme_public_military"),
    })[scheme] ?? scheme;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Landmark className="h-4 w-4" aria-hidden="true" />{" "}
          {t("pension_rules.title")}
          <Lock
            className="h-3.5 w-3.5 text-muted-foreground"
            aria-hidden="true"
          />
        </CardTitle>
        <p className="mt-1 text-sm text-muted-foreground">
          {t(
            "pension_rules.locked_description",
            "Contribution rates as the law sets them for each scheme. Payroll applies them as they stand; an organisation cannot change them.",
          )}
        </p>
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
      </CardContent>
    </Card>
  );
}
