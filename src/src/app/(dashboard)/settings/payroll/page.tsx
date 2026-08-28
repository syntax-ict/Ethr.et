"use client";

import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { AllowanceRulesCard } from "@/features/payroll/components/allowance-rules-card";
import { OvertimeRatesCard } from "@/features/payroll/components/overtime-rates-card";
import { PayrollScheduleCard } from "@/features/payroll/components/payroll-schedule-card";
import { TaxBracketsCard } from "@/features/payroll/components/tax-brackets-card";
import { useT } from "@/lib/i18n/useT";

export default function PayrollConfigPage() {
  const { t } = useT();

  return (
    <RoleGate minRole="tenant_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("payroll_config.title", "Payroll Configuration")}
          description={t(
            "payroll_config.description",
            "Allowances, income tax brackets, and overtime rates used by every payroll run.",
          )}
        />

        <PayrollScheduleCard />
        <AllowanceRulesCard />
        <TaxBracketsCard />
        <OvertimeRatesCard />
      </div>
    </RoleGate>
  );
}
