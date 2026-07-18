"use client";

import dynamic from "next/dynamic";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
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

export default function AnalyticsPage() {
  const { t } = useT();
  return (
    <RoleGate minRole="tenant_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("analytics_page.title")}
          description={t("analytics_page.description")}
        />

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
            <LazyOverviewTab />
          </TabsContent>
          <TabsContent value="attendance" className="mt-4">
            <LazyAttendanceTab />
          </TabsContent>
          <TabsContent value="payroll" className="mt-4">
            <LazyPayrollTab />
          </TabsContent>
          <TabsContent value="workforce" className="mt-4">
            <LazyWorkforceTab />
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
