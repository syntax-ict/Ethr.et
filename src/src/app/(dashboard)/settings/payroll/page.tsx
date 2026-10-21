"use client";

import { RoleGate } from "@/components/shared/role-gate";
import { AllowanceRulesCard } from "@/features/payroll/components/allowance-rules-card";
import { OvertimeRatesCard } from "@/features/payroll/components/overtime-rates-card";
import { PayrollScheduleCard } from "@/features/payroll/components/payroll-schedule-card";
import { StatutoryDetailsCard } from "@/features/payroll/components/statutory-details-card";
import { TaxBracketsCard } from "@/features/payroll/components/tax-brackets-card";
import { PensionRulesCard } from "@/features/payroll/components/pension-rules-card";

export default function PayrollConfigPage() {
  return (
    <RoleGate minRole="tenant_admin">
      <div className="space-y-6">
        <PayrollScheduleCard />
        <StatutoryDetailsCard />
        <AllowanceRulesCard />
        <TaxBracketsCard />
        <PensionRulesCard />
        <OvertimeRatesCard />
      </div>
    </RoleGate>
  );
}
