"use client";

import { RoleGate } from "@/components/shared/role-gate";
import { PlanFeatureNotice } from "@/components/shared/plan-feature-notice";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { usePlanFeatures } from "@/features/auth/api";
import { AllowanceRulesCard } from "@/features/payroll/components/allowance-rules-card";
import { OvertimeRatesCard } from "@/features/payroll/components/overtime-rates-card";
import { PayChangeChecksCard } from "@/features/payroll/components/pay-change-checks-card";
import { PayrollScheduleCard } from "@/features/payroll/components/payroll-schedule-card";
import { PrintedDocumentsCard } from "@/features/payroll/components/printed-documents-card";
import { StatutoryDetailsCard } from "@/features/payroll/components/statutory-details-card";
import { StatutoryRulesCard } from "@/features/payroll/components/statutory-rules-card";
import { TaxBracketsCard } from "@/features/payroll/components/tax-brackets-card";
import { PensionRulesCard } from "@/features/payroll/components/pension-rules-card";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useQueryTab } from "@/lib/hooks/useQueryTab";
import { useT } from "@/lib/i18n/useT";

const TABS = ["setup", "rules"] as const;

export default function PayrollConfigPage() {
  const { t } = useT();
  const { can } = usePermissions();
  const hasPayroll = usePlanFeatures().has("payroll");
  // `?tab=rules` opens the statutory rules: the readiness checklist links
  // straight to them.
  const [tab, setTab] = useQueryTab(TABS, "setup");

  return (
    // The rule endpoints authorize `payroll.viewConfig` to read and
    // `payroll.manageConfig` to change; the cards below hide what a viewer
    // cannot do.
    <RoleGate anyPermission={["viewPayrollConfig"]}>
      <div className="space-y-4">
        {!hasPayroll && <PlanFeatureNotice feature="payroll" />}
        {!can.managePayrollConfig && (
          <p
            role="note"
            className="rounded-lg border bg-muted/30 p-3 text-sm text-muted-foreground"
          >
            {t(
              "payroll_config.read_only",
              "You can view the allowance, tax, pension, overtime and statutory rules but not change them. Changing them needs the Manage payroll configuration permission, so ask your organisation's administrator.",
            )}
          </p>
        )}
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
            {/* These four read and save the organisation's settings, which
                take `settings.manage`: offered to anyone without it, they
                would only fail to load. */}
            {can.manageSettings && (
              <>
                <PayrollScheduleCard />
                <StatutoryDetailsCard />
                <PrintedDocumentsCard />
                <PayChangeChecksCard />
              </>
            )}
            <AllowanceRulesCard />
            <TaxBracketsCard />
            <PensionRulesCard />
            <OvertimeRatesCard />
          </TabsContent>
          <TabsContent value="rules" className="mt-6">
            <StatutoryRulesCard onEdit={() => setTab("setup")} />
          </TabsContent>
        </Tabs>
      </div>
    </RoleGate>
  );
}
