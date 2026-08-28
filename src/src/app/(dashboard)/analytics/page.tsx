"use client";

import { useState } from "react";
import dynamic from "next/dynamic";
import { Download, Mail } from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { BranchScopeSelector } from "@/features/dashboard/components/branch-scope-selector";
import { DashboardDigestDialog } from "@/features/dashboard/components/dashboard-digest-dialog";
import { useExecutiveOverview } from "@/features/dashboard/executive-api";
import { downloadCsv } from "@/lib/utils/csv-export";
import { useT } from "@/lib/i18n/useT";

const LazyOverviewTab = dynamic(
  () => import("./overview-tab").then((m) => ({ default: m.OverviewTab })),
  { ssr: false, loading: () => <ChartSkeleton /> },
);
const LazyAttendanceTab = dynamic(
  () => import("./attendance-tab").then((m) => ({ default: m.AttendanceTab })),
  { ssr: false, loading: () => <ChartSkeleton /> },
);
const LazyPayrollTab = dynamic(
  () => import("./payroll-tab").then((m) => ({ default: m.PayrollTab })),
  { ssr: false, loading: () => <ChartSkeleton /> },
);
const LazyWorkforceTab = dynamic(
  () => import("./workforce-tab").then((m) => ({ default: m.WorkforceTab })),
  { ssr: false, loading: () => <ChartSkeleton /> },
);

interface DepartmentHeadcount {
  department: string;
  count: number;
}

export default function AnalyticsPage() {
  const { t } = useT();
  const [branchPublicId, setBranchPublicId] = useState<string | undefined>();
  const [digestOpen, setDigestOpen] = useState(false);

  // Shared with the Overview tab's own query — same key, same cache entry, so
  // this doesn't cost a second request. Exports the tenant-level summary
  // regardless of which tab is active, since that's what a CEO/HR Director
  // hands off in a meeting: the headline numbers, not one chart's raw rows.
  const overview = useExecutiveOverview({ branchPublicId });

  function handleExport() {
    const data = overview.data;
    if (!data) return;

    const byDepartment: DepartmentHeadcount[] =
      data.headcount?.by_department ?? [];

    downloadCsv(
      `ethr-executive-summary-${new Date().toISOString().slice(0, 10)}.csv`,
      [
        {
          metric: "Headcount (active)",
          value: data.headcount?.active ?? 0,
        },
        {
          metric: "Headcount (total)",
          value: data.headcount?.total ?? 0,
        },
        {
          metric: "Attendance rate today (%)",
          value: data.attendance_rate?.today ?? 0,
        },
        {
          metric: "Payroll net (ETB cents)",
          value: data.payroll_summary?.net_cents ?? 0,
        },
        {
          metric: "Turnover rate (%)",
          value: data.turnover?.rate ?? 0,
        },
        {
          metric: "Leave utilization (%)",
          value: data.leave_utilization?.utilization_rate ?? 0,
        },
        ...byDepartment.map((d) => ({
          metric: `Headcount — ${d.department}`,
          value: d.count,
        })),
      ],
    );
  }

  return (
    <RoleGate
      anyPermission={["viewExecutiveDashboard", "viewRegionalDashboard"]}
    >
      <div className="space-y-6">
        <PageHeader
          title={t("analytics_page.title")}
          description={t("analytics_page.description")}
          actions={
            <div className="flex flex-wrap items-center gap-2">
              <BranchScopeSelector
                value={branchPublicId}
                onChange={setBranchPublicId}
              />
              <Button
                variant="outline"
                size="sm"
                onClick={handleExport}
                disabled={!overview.data}
              >
                <Download className="mr-2 h-4 w-4" />
                {t("executive_dashboard.export_csv", "Export CSV")}
              </Button>
              <Button
                variant="outline"
                size="sm"
                onClick={() => setDigestOpen(true)}
              >
                <Mail className="mr-2 h-4 w-4" />
                {t("digest_dialog.trigger", "Email digest")}
              </Button>
            </div>
          }
        />

        {digestOpen && (
          <DashboardDigestDialog
            open={digestOpen}
            onClose={() => setDigestOpen(false)}
            branchPublicId={branchPublicId}
          />
        )}

        <Tabs defaultValue="overview">
          <TabsList>
            <TabsTrigger value="overview">
              {t("analytics_page.overview")}
            </TabsTrigger>
            <TabsTrigger value="attendance">{t("nav.attendance")}</TabsTrigger>
            <TabsTrigger value="payroll">{t("nav.payroll")}</TabsTrigger>
            <TabsTrigger value="workforce">
              {t("analytics_page.workforce")}
            </TabsTrigger>
          </TabsList>

          <TabsContent value="overview" className="mt-4">
            <LazyOverviewTab branchPublicId={branchPublicId} />
          </TabsContent>
          <TabsContent value="attendance" className="mt-4">
            <LazyAttendanceTab branchPublicId={branchPublicId} />
          </TabsContent>
          <TabsContent value="payroll" className="mt-4">
            <LazyPayrollTab branchPublicId={branchPublicId} />
          </TabsContent>
          <TabsContent value="workforce" className="mt-4">
            <LazyWorkforceTab branchPublicId={branchPublicId} />
          </TabsContent>
        </Tabs>
      </div>
    </RoleGate>
  );
}

function ChartSkeleton() {
  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-28" />
        ))}
      </div>
      <Skeleton className="h-80 w-full" />
    </div>
  );
}
