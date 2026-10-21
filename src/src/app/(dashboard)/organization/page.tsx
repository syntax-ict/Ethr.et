"use client";

import {
  Building2,
  GitBranch,
  Users2,
  Briefcase,
  Award,
  Wallet,
  Network,
  Users,
} from "lucide-react";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { RoleGate } from "@/components/shared/role-gate";
import { OrgChart } from "@/features/organization/components/org-chart";
import { ReportingChart } from "@/features/organization/components/reporting-chart";
import { BranchesSection } from "@/features/organization/components/sections/branches-section";
import { DepartmentsSection } from "@/features/organization/components/sections/departments-section";
import { TeamsSection } from "@/features/organization/components/sections/teams-section";
import { PositionsSection } from "@/features/organization/components/sections/positions-section";
import { GradesSection } from "@/features/organization/components/sections/grades-section";
import { CostCentersSection } from "@/features/organization/components/sections/cost-centers-section";
import { useT } from "@/lib/i18n/useT";
import { useQueryTab } from "@/lib/hooks/useQueryTab";

const ORG_TABS = [
  "structure",
  "reporting",
  "branches",
  "departments",
  "teams",
  "positions",
  "grades",
  "cost-centers",
] as const;

/**
 * Each unit is its own section in `features/organization/components/sections`,
 * built on the shared `CrudSection`. This file held all six, 1,549 lines of
 * the same state, submit, delete and dialog code six times over.
 */
export default function OrganizationPage() {
  const { t } = useT();
  // `?tab=departments` is how search opens a department.
  const [tab, setTab] = useQueryTab(ORG_TABS, "structure");
  return (
    <RoleGate anyPermission={["manageOrg"]}>
      <div className="space-y-6">
        <Tabs value={tab} onValueChange={setTab}>
          <TabsList variant="underline">
            <TabsTrigger value="structure">
              <Network className="mr-1.5 h-3.5 w-3.5" />{" "}
              {t("org.tab.structure", "Structure")}
            </TabsTrigger>
            <TabsTrigger value="reporting">
              <Users className="mr-1.5 h-3.5 w-3.5" />{" "}
              {t("org.tab.reporting", "Reporting")}
            </TabsTrigger>
            <TabsTrigger value="branches">
              <Building2 className="mr-1.5 h-3.5 w-3.5" />{" "}
              {t("org.tab.branches", "Branches")}
            </TabsTrigger>
            <TabsTrigger value="departments">
              <GitBranch className="mr-1.5 h-3.5 w-3.5" />{" "}
              {t("org.tab.departments", "Departments")}
            </TabsTrigger>
            <TabsTrigger value="teams">
              <Users2 className="mr-1.5 h-3.5 w-3.5" />{" "}
              {t("org.tab.teams", "Teams")}
            </TabsTrigger>
            <TabsTrigger value="positions">
              <Briefcase className="mr-1.5 h-3.5 w-3.5" />{" "}
              {t("org.tab.positions", "Positions")}
            </TabsTrigger>
            <TabsTrigger value="grades">
              <Award className="mr-1.5 h-3.5 w-3.5" />{" "}
              {t("org.tab.grades", "Grades")}
            </TabsTrigger>
            <TabsTrigger value="cost-centers">
              <Wallet className="mr-1.5 h-3.5 w-3.5" />{" "}
              {t("org.tab.cost_centers", "Cost Centers")}
            </TabsTrigger>
          </TabsList>

          <TabsContent value="structure" className="mt-4">
            <OrgChart />
          </TabsContent>
          <TabsContent value="reporting" className="mt-4">
            <ReportingChart />
          </TabsContent>
          <TabsContent value="branches" className="mt-4">
            <BranchesSection />
          </TabsContent>
          <TabsContent value="departments" className="mt-4">
            <DepartmentsSection />
          </TabsContent>
          <TabsContent value="teams" className="mt-4">
            <TeamsSection />
          </TabsContent>
          <TabsContent value="positions" className="mt-4">
            <PositionsSection />
          </TabsContent>
          <TabsContent value="grades" className="mt-4">
            <GradesSection />
          </TabsContent>
          <TabsContent value="cost-centers" className="mt-4">
            <CostCentersSection />
          </TabsContent>
        </Tabs>
      </div>
    </RoleGate>
  );
}
