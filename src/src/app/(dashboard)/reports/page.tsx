"use client";

import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { RoleGate } from "@/components/shared/role-gate";
import { useT } from "@/lib/i18n/useT";
import { useQueryTab } from "@/lib/hooks/useQueryTab";
import { PlanFeatureNotice } from "@/components/shared/plan-feature-notice";
import { usePlanFeatures } from "@/features/auth/api";
import { BuilderTab } from "@/features/reports/components/builder-tab";
import { QuickTab } from "@/features/reports/components/quick-tab";
import { SavedTab } from "@/features/reports/components/saved-tab";
import { ScheduledTab } from "@/features/reports/components/scheduled-tab";

const REPORT_TABS = ["builder", "quick", "saved", "scheduled"] as const;

export default function ReportsPage() {
  const { t } = useT();
  // Generating and exporting need the plan's `reports` feature; saving and
  // scheduling need `custom_reports`. The page offered all of them on every
  // plan, and each was refused with a 403 shown as "failed" (audit N66).
  const plan = usePlanFeatures();
  // `?tab=` opens a tab and switching writes it back, so a reload or a
  // shared link keeps the tab (FRONTEND_REDESIGN_PLAN.md, backlog 21).
  const [tab, setTab] = useQueryTab(REPORT_TABS, "builder");

  return (
    <RoleGate anyPermission={["viewReports"]}>
      <div className="space-y-6">
        {!plan.has("reports") ? (
          <PlanFeatureNotice />
        ) : (
          <Tabs value={tab} onValueChange={setTab}>
            <TabsList>
              <TabsTrigger value="builder">
                {t("reports_page.builder")}
              </TabsTrigger>
              <TabsTrigger value="quick">
                {t("reports_page.quick_reports")}
              </TabsTrigger>
              <TabsTrigger value="saved">{t("reports_page.saved")}</TabsTrigger>
              <TabsTrigger value="scheduled">
                {t("reports_page.scheduled")}
              </TabsTrigger>
            </TabsList>

            <TabsContent value="builder" className="mt-4">
              <BuilderTab />
            </TabsContent>
            <TabsContent value="quick" className="mt-4">
              <QuickTab />
            </TabsContent>
            <TabsContent value="saved" className="mt-4">
              <SavedTab />
            </TabsContent>
            <TabsContent value="scheduled" className="mt-4">
              {plan.has("custom_reports") ? (
                <ScheduledTab />
              ) : (
                <PlanFeatureNotice />
              )}
            </TabsContent>
          </Tabs>
        )}
      </div>
    </RoleGate>
  );
}

// ── BUILDER TAB ────────────────────────────────────────────────
