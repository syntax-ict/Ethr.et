"use client";

import Link from "next/link";
import {
  Building2,
  TrendingUp,
  Activity,
  Users,
  ArrowRight,
  ScrollText,
  XCircle,
  Pause,
  RefreshCw,
  AlertTriangle,
  Loader2,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import {
  useAdminAuditLog,
  useFailedJobs,
  useRetryFailedJob,
  useRetryAllFailedJobs,
} from "@/features/admin/api";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

export default function AdminConsolePage() {
  const { t } = useT();
  const { data: revenue, isLoading: revLoading } = useQuery({
    queryKey: ["admin", "revenue"],
    queryFn: async () => {
      const { data } = await apiClient.get("/admin/revenue");
      return data;
    },
  });

  const { data: health, isLoading: healthLoading } = useQuery({
    queryKey: ["admin", "health"],
    queryFn: async () => {
      const { data } = await apiClient.get("/admin/health");
      return data;
    },
  });

  const { data: audit } = useAdminAuditLog({ page: 1 });
  const { data: failedJobsData } = useFailedJobs({ page: 1 });
  const retryJob = useRetryFailedJob();
  const retryAll = useRetryAllFailedJobs();

  return (
    <RoleGate allowedRoles={["super_admin"]}>
      <div className="space-y-6">
        <PageHeader
          title={t("admin_console_page.title")}
          description={t("admin_console_page.description")}
          actions={
            <Button asChild>
              <Link href="/admin/tenants">
                {t("admin_console_page.manage_tenants")}{" "}
                <ArrowRight className="ml-2 h-4 w-4" />
              </Link>
            </Button>
          }
        />

        {revLoading ? (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-28" />
            ))}
          </div>
        ) : revenue ? (
          <>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <MetricCard
                icon={Building2}
                title={t("admin_console_page.total_tenants")}
                value={String(revenue.total_tenants)}
                color="blue"
              />
              <MetricCard
                icon={Users}
                title={t("webhooks_page.active")}
                value={String(revenue.active_tenants)}
                color="green"
              />
              <MetricCard
                icon={TrendingUp}
                title={t("admin_console_page.trial")}
                value={String(revenue.trial_tenants)}
                color="amber"
              />
              <MetricCard
                icon={Activity}
                title={t("admin_console_page.conversion")}
                value={`${revenue.conversion_rate}%`}
                color="purple"
              />
            </div>
            {(revenue.suspended_tenants > 0 ||
              revenue.cancelled_tenants > 0) && (
              <div className="grid gap-4 sm:grid-cols-2">
                <MetricCard
                  icon={Pause}
                  title={t("admin_console_page.suspended")}
                  value={String(revenue.suspended_tenants ?? 0)}
                  color="amber"
                />
                <MetricCard
                  icon={XCircle}
                  title={t("admin_console_page.cancelled")}
                  value={String(revenue.cancelled_tenants ?? 0)}
                  color="red"
                />
              </div>
            )}
          </>
        ) : null}

        <div className="grid gap-6 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">
                {t("admin_console_page.system_health")}
              </CardTitle>
            </CardHeader>
            <CardContent>
              {healthLoading ? (
                <Skeleton className="h-20 w-full" />
              ) : health ? (
                <div className="space-y-3">
                  {Object.entries(
                    health.services as Record<string, { status: string }>,
                  ).map(([name, svc]) => (
                    <div
                      key={name}
                      className="flex items-center justify-between"
                    >
                      <span className="text-sm capitalize text-foreground">
                        {name}
                      </span>
                      <div className="flex items-center gap-2">
                        <div
                          className={`h-2 w-2 rounded-full ${svc.status === "healthy" ? "bg-green-500" : "bg-red-500"}`}
                        />
                        <span className="text-sm text-muted-foreground">
                          {svc.status}
                        </span>
                      </div>
                    </div>
                  ))}
                  <div className="flex items-center justify-between border-t pt-3">
                    <span className="text-sm text-foreground">
                      {t("admin_console_page.failed_jobs")}
                    </span>
                    <span className="text-sm font-medium text-foreground">
                      {health.failed_jobs}
                    </span>
                  </div>
                </div>
              ) : null}
            </CardContent>
          </Card>

          {revenue?.monthly_trend && (
            <Card>
              <CardHeader>
                <CardTitle className="text-base">
                  {t("admin_console_page.monthly_revenue_trend")}
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="space-y-2">
                  {(
                    revenue.monthly_trend as Array<{
                      month: string;
                      revenue_cents: number;
                    }>
                  )
                    .slice(-6)
                    .map((m) => (
                      <div
                        key={m.month}
                        className="flex items-center justify-between"
                      >
                        <span className="text-sm text-muted-foreground">
                          {m.month}
                        </span>
                        <span className="text-sm font-medium text-foreground">
                          {(m.revenue_cents / 100).toLocaleString("en-ET", {
                            minimumFractionDigits: 2,
                          })}{" "}
                          ETB
                        </span>
                      </div>
                    ))}
                </div>
              </CardContent>
            </Card>
          )}
        </div>

        {/* Failed Jobs */}
        {failedJobsData && failedJobsData.data.length > 0 && (
          <Card className="border-destructive/30">
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle className="flex items-center gap-2 text-base">
                <AlertTriangle className="h-4 w-4 text-destructive" />
                {t("admin_console_page.failed_jobs")} (
                {failedJobsData.total ?? failedJobsData.data.length})
              </CardTitle>
              <Button
                variant="outline"
                size="sm"
                onClick={() =>
                  retryAll.mutate(undefined, {
                    onSuccess: (d) => toast.success(d.message),
                    onError: () =>
                      toast.error(t("admin_console_page.retry_failed")),
                  })
                }
                disabled={retryAll.isPending}
              >
                {retryAll.isPending ? (
                  <Loader2 className="mr-1 h-3 w-3 animate-spin" />
                ) : (
                  <RefreshCw className="mr-1 h-3 w-3" />
                )}
                {t("admin_console_page.retry_all")}
              </Button>
            </CardHeader>
            <CardContent>
              <div className="space-y-2">
                {failedJobsData.data.slice(0, 10).map((job) => {
                  const jobName = (() => {
                    try {
                      const parsed = JSON.parse(job.payload);
                      return (parsed.displayName ?? "Unknown")
                        .split("\\")
                        .pop();
                    } catch {
                      return "Unknown";
                    }
                  })();
                  return (
                    <div
                      key={job.uuid}
                      className="flex items-center justify-between gap-3 rounded-lg border p-2"
                    >
                      <div className="min-w-0">
                        <p className="text-sm font-medium truncate">
                          {jobName}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          {job.queue} &middot;{" "}
                          {new Date(job.failed_at).toLocaleString()}
                        </p>
                      </div>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                          retryJob.mutate(job.uuid, {
                            onSuccess: () =>
                              toast.success(
                                t("admin_console_page.job_retried"),
                              ),
                            onError: () =>
                              toast.error(t("admin_console_page.retry_failed")),
                          })
                        }
                        disabled={retryJob.isPending}
                      >
                        <RefreshCw className="h-3 w-3" />
                      </Button>
                    </div>
                  );
                })}
              </div>
            </CardContent>
          </Card>
        )}

        {/* Platform audit activity (cross-tenant) */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <ScrollText className="h-4 w-4" />
              {t("admin_console_page.recent_activity")}
            </CardTitle>
          </CardHeader>
          <CardContent>
            {!audit ? (
              <Skeleton className="h-20 w-full" />
            ) : audit.data.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                {t("admin_console_page.no_recent_activity")}
              </p>
            ) : (
              <div className="space-y-2">
                {audit.data.slice(0, 10).map((log) => (
                  <div
                    key={log.id}
                    className="flex items-center justify-between gap-3 rounded-lg border p-2"
                  >
                    <div className="flex items-center gap-2 min-w-0">
                      <Badge
                        variant="outline"
                        className="font-mono text-[10px]"
                      >
                        {log.action}
                      </Badge>
                      {log.auditable_type && (
                        <span className="text-xs text-muted-foreground truncate">
                          {log.auditable_type.split("\\").pop()}#
                          {log.auditable_id}
                        </span>
                      )}
                    </div>
                    <span className="text-xs text-muted-foreground shrink-0">
                      {new Date(log.created_at).toLocaleString()}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </RoleGate>
  );
}

const colorMap: Record<string, { bg: string; text: string }> = {
  blue: {
    bg: "bg-blue-100 dark:bg-blue-950",
    text: "text-blue-600 dark:text-blue-400",
  },
  green: {
    bg: "bg-green-100 dark:bg-green-950",
    text: "text-green-600 dark:text-green-400",
  },
  amber: {
    bg: "bg-amber-100 dark:bg-amber-950",
    text: "text-amber-600 dark:text-amber-400",
  },
  purple: {
    bg: "bg-purple-100 dark:bg-purple-950",
    text: "text-purple-600 dark:text-purple-400",
  },
  red: {
    bg: "bg-red-100 dark:bg-red-950",
    text: "text-red-600 dark:text-red-400",
  },
};

function MetricCard({
  icon: Icon,
  title,
  value,
  color,
}: {
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  value: string;
  color: string;
}) {
  const c = colorMap[color] ?? colorMap.blue;
  return (
    <Card>
      <CardContent className="flex items-center gap-4 p-5">
        <div
          className={`flex h-11 w-11 items-center justify-center rounded-xl ${c.bg}`}
        >
          <Icon className={`h-5 w-5 ${c.text}`} />
        </div>
        <div>
          <p className="text-sm text-muted-foreground">{title}</p>
          <p className="text-2xl font-bold text-foreground">{value}</p>
        </div>
      </CardContent>
    </Card>
  );
}
