"use client";

import { RoleGate } from "@/components/shared/role-gate";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { AllowanceRulesCard } from "@/features/payroll/components/allowance-rules-card";
import { OvertimeRatesCard } from "@/features/payroll/components/overtime-rates-card";
import { PayrollScheduleCard } from "@/features/payroll/components/payroll-schedule-card";
import { StatutoryDetailsCard } from "@/features/payroll/components/statutory-details-card";
import { StatutoryRulesCard } from "@/features/payroll/components/statutory-rules-card";
import { TaxBracketsCard } from "@/features/payroll/components/tax-brackets-card";
import { PensionRulesCard } from "@/features/payroll/components/pension-rules-card";
import { useQueryTab } from "@/lib/hooks/useQueryTab";
import { useT } from "@/lib/i18n/useT";

const TABS = ["setup", "rules"] as const;

export default function PayrollConfigPage() {
  const { t } = useT();
  // `?tab=rules` opens the statutory rules: the readiness checklist links
  // straight to them.
  const [tab, setTab] = useQueryTab(TABS, "setup");

  return (
    <RoleGate minRole="tenant_admin">
      <Tabs value={tab} onValueChange={setTab} className="w-full">
        <TabsList>
          <TabsTrigger value="setup">
            {t("payroll_config.tab_setup", "Payroll setup")}
          </TabsTrigger>
          <TabsTrigger value="rules">
            {t("payroll_config.tab_rules", "Statutory rules")}
          </TabsTrigger>
        </TabsList>
        <TabsContent value="setup" className="mt-6 space-y-6">
          <PayrollScheduleCard />
          <StatutoryDetailsCard />
          <AllowanceRulesCard />
          <TaxBracketsCard />
          <PensionRulesCard />
          <OvertimeRatesCard />
        </TabsContent>
        <TabsContent value="rules" className="mt-6">
          <StatutoryRulesCard onEdit={() => setTab("setup")} />
        </TabsContent>
      </Tabs>
    </RoleGate>
  );
}
