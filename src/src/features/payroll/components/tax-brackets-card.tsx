"use client";

import { Lock } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { SimpleTable } from "@/components/shared/simple-table";
import { useT } from "@/lib/i18n/useT";
import { formatETB } from "@/lib/utils/currency";
import { useTaxBrackets, type TaxBracket } from "../api";

/**
 * The income tax ladder payroll applies, shown and never edited. Income tax
 * is the law's exactly (Proclamation 1395/2025): since 2026-11-09 an
 * organisation cannot save its own ladder, and the API refuses one. A ladder
 * saved before then is still listed while it is in force, and the readiness
 * checklist flags it.
 */
export function TaxBracketsCard() {
  const { t } = useT();
  const query = useTaxBrackets();

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Lock className="h-4 w-4" aria-hidden="true" />
          {t("payroll_config.tax_brackets", "Income tax brackets")}
        </CardTitle>
        <p className="mt-1 text-sm text-muted-foreground">
          {t(
            "payroll_config.tax_brackets_locked",
            "Monthly employment income tax as the law sets it. Payroll applies it as it stands; an organisation cannot change it.",
          )}
        </p>
      </CardHeader>

      <CardContent>
        <QueryBoundary query={query} isEmpty={() => false}>
          {(data) => (
            <div className="overflow-x-auto">
              <div className="min-w-[480px]">
                <SimpleTable
                  caption={t(
                    "payroll_config.tax_brackets",
                    "Income tax brackets",
                  )}
                  headers={[
                    t("payroll_config.from", "From"),
                    t("payroll_config.to", "To"),
                    t("payroll_config.rate_percent", "Rate (%)"),
                    t("payroll_config.deduction_etb", "Deduction (ETB)"),
                  ]}
                  rows={data.data.map((band: TaxBracket) => ({
                    key: band.public_id,
                    cells: [
                      <span key="from" className="font-mono">
                        {formatETB(band.min_amount_cents)}
                      </span>,
                      <span key="to" className="font-mono">
                        {band.max_amount_cents === null
                          ? t("payroll_config.and_above", "and above")
                          : formatETB(band.max_amount_cents)}
                      </span>,
                      <span key="rate" className="font-mono">
                        {band.rate}
                      </span>,
                      <span key="deduction" className="font-mono">
                        {formatETB(band.deduction_cents)}
                      </span>,
                    ],
                  }))}
                />
              </div>
            </div>
          )}
        </QueryBoundary>
      </CardContent>
    </Card>
  );
}
