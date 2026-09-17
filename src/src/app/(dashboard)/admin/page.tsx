"use client";

import { useState } from "react";
import Link from "next/link";
import {
  Building2,
  TrendingUp,
  Activity,
  Users,
  ArrowRight,
  ScrollText,
  Layers,
  RefreshCw,
  AlertTriangle,
  Loader2,
  Wallet,
  CheckCircle2,
  CircleDot,
  Database,
  HardDrive,
  Radio,
  Server,
  Gauge,
  Settings,
  ShieldAlert,
  Trash2,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { useCurrentUser } from "@/features/auth/api";
import { KpiCard } from "@/features/dashboard/components/kpi-card";
import { useQueryClient } from "@tanstack/react-query";
import {
  useAdminRevenue,
  useAdminHealth,
  useAdminAuditLog,
  useFailedJobs,
  useRetryFailedJob,
  useRetryAllFailedJobs,
  useDismissFailedJob,
} from "@/features/admin/api";
import { formatETB } from "@/lib/utils/currency";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

function timeAgo(
  dateStr: string,
  t: (key: string, fallback: string) => string,
): string {
  const diff = Date.now() - new Date(dateStr).getTime();
  const mins = Math.floor(diff / 60_000);
  if (mins < 1) return t("time.just_now", "just now");
  if (mins < 60)
    return `${mins}${t("time.min_short", "m")} ${t("time.ago", "ago")}`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24)
    return `${hrs}${t("time.hour_short", "h")} ${t("time.ago", "ago")}`;
  const days = Math.floor(hrs / 24);
  return `${days}${t("time.day_short", "d")} ${t("time.ago", "ago")}`;
}

function formatAction(action: string): string {
  return action.replace(/\./g, " › ").replace(/_/g, " ");
}

export default function AdminConsolePage() {
  const { t } = useT();
  const queryClient = useQueryClient();

  const {
    data: revenue,
    isLoading: revLoading,
    isError: revError,
    refetch: refetchRevenue,
  } = useAdminRevenue();
  const {
    data: health,
    isLoading: healthLoading,
    isError: healthError,
    refetch: refetchHealth,
    dataUpdatedAt: healthUpdatedAt,
  } = useAdminHealth();

  const { data: audit } = useAdminAuditLog({ page: 1 });
  const { data: failedJobsData } = useFailedJobs({ page: 1 });
  const retryJob = useRetryFailedJob();
  const retryAll = useRetryAllFailedJobs();
  const dismissJob = useDismissFailedJob();
  const [dismissTarget, setDismissTarget] = useState<string | null>(null);

  // Platform writes require MFA (RequirePlatformMfa on the admin routes).
  // Surfacing it here means an un-enrolled operator reads why before hitting
  // a 403, rather than after.
  const { data: currentUser } = useCurrentUser();
  const needsMfa = currentUser ? !currentUser.mfa_enabled : false;

  const unhealthyServices = health
    ? Object.entries(health.services).filter(
        ([, s]) => s.status !== "healthy" && s.status !== "unknown",
      )
    : [];
  const failedJobCount = health?.failed_jobs ?? 0;
  const hasAlerts = unhealthyServices.length > 0 || failedJobCount > 0;

  return (
    <RoleGate minRole="super_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("admin_console_page.title", "Admin Console")}
          description={t(
            "admin_console_page.description",
            "Platform overview and system management",
          )}
          actions={
            <Button asChild>
              <Link href="/admin/tenants">
                {t("admin_console_page.manage_tenants", "Manage Tenants")}{" "}
                <ArrowRight className="ml-2 h-4 w-4" />
              </Link>
            </Button>
          }
        />

        {/* ── MFA enrolment required ─────────────────────────────────── */}
        {needsMfa && (
          <div className="rounded-xl border border-status-warning/40 bg-status-warning/5 p-4">
            <div className="flex items-start gap-3">
              <ShieldAlert className="mt-0.5 h-5 w-5 shrink-0 text-status-warning" />
              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-foreground">
                  {t(
                    "admin_console_page.mfa_required_title",
                    "Two-factor authentication required",
                  )}
                </p>
                {/* text-foreground, not text-muted-foreground: the warning
                    tint behind this block (bg-status-warning/5 → #fbf6f3) drops
                    muted text to 4.43:1, just under WCAG AA's 4.5:1. Muted
                    passes on the page's plain white surfaces, so it is the
                    tinted background that makes this one different. The
                    sibling title already uses this token and is distinguished
                    by weight rather than colour. */}
                <p className="mt-1 text-sm text-foreground">
                  {t(
                    "admin_console_page.mfa_required_body",
                    "This account can reach every tenant on the platform. Until two-factor authentication is enabled you can view the console but cannot make changes.",
                  )}
                </p>
              </div>
              <Button variant="outline" size="sm" asChild className="shrink-0">
                <Link href="/profile/security">
                  {t("admin_console_page.enable_mfa", "Enable now")}
                </Link>
              </Button>
            </div>
          </div>
        )}

        {/* ── Critical alerts banner ─────────────────────────────────── */}
        {hasAlerts && !healthLoading && (
          <div className="rounded-xl border border-status-error/30 bg-status-error/5 p-4">
            <div className="flex items-start gap-3">
              <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-status-error" />
              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-foreground">
                  {t(
                    "admin_console_page.attention_required",
                    "Attention Required",
                  )}
                </p>
                <div className="mt-1 flex flex-wrap gap-2">
                  {unhealthyServices.map(([name, svc]) => (
                    <Badge
                      key={name}
                      variant="outline"
                      className="border-status-error/30 text-destructive-on-soft"
                    >
                      {name}: {svc.status}
                    </Badge>
                  ))}
                  {failedJobCount > 0 && (
                    <Badge
                      variant="outline"
                      className="border-status-error/30 text-destructive-on-soft"
                    >
                      {failedJobCount}{" "}
                      {t("admin_console_page.failed_jobs_label", "failed jobs")}
                    </Badge>
                  )}
                </div>
              </div>
            </div>
          </div>
        )}

        {/* ── Quick Actions ──────────────────────────────────────────── */}
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" size="sm" asChild>
            <Link href="/admin/tenants">
              <Building2 className="mr-1.5 h-3.5 w-3.5" />
              {t("admin_console_page.tenants", "Tenants")}
            </Link>
          </Button>
          <Button variant="outline" size="sm" asChild>
            <Link href="/admin/audit">
              <ScrollText className="mr-1.5 h-3.5 w-3.5" />
              {t("admin_console_page.audit_log", "Audit Log")}
            </Link>
          </Button>
          {/* Platform settings, not the tenant /settings hub this used to point
              at — the bank account here is the one every tenant pays into. */}
          <Button variant="outline" size="sm" asChild>
            <Link href="/admin/platform-settings">
              <Settings className="mr-1.5 h-3.5 w-3.5" />
              {t("admin_console_page.platform_settings", "Platform Settings")}
            </Link>
          </Button>
          <Button variant="outline" size="sm" asChild>
            <Link href="/admin/plans">
              <Layers className="mr-1.5 h-3.5 w-3.5" />
              {t("admin_console_page.plans", "Plans")}
            </Link>
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              queryClient.invalidateQueries({ queryKey: ["admin"] });
              toast.success(
                t("admin_console_page.refreshed", "Dashboard refreshed"),
              );
            }}
          >
            <RefreshCw className="mr-1.5 h-3.5 w-3.5" />
            {t("admin_console_page.refresh_all", "Refresh All")}
          </Button>
        </div>

        {/* ── KPI cards ──────────────────────────────────────────────── */}
        {revLoading ? (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            {Array.from({ length: 5 }).map((_, i) => (
              <Skeleton key={i} className="h-28 rounded-xl" />
            ))}
          </div>
        ) : revError ? (
          <ErrorCard
            message={t(
              "admin_console_page.revenue_load_failed",
              "Couldn't load revenue data",
            )}
            onRetry={() => refetchRevenue()}
          />
        ) : revenue ? (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <KpiCard
              icon={Building2}
              tone="primary"
              title={t("admin_console_page.total_tenants", "Total Tenants")}
              value={String(revenue.total_tenants)}
              sub={`${revenue.active_tenants} ${t("admin_console_page.active_sub", "active")}`}
            />
            <KpiCard
              icon={Users}
              tone="success"
              title={t("admin_console_page.active_label", "Active")}
              value={String(revenue.active_tenants)}
              sub={t("admin_console_page.paying_customers", "Paying customers")}
            />
            <KpiCard
              icon={TrendingUp}
              tone="warning"
              title={t("admin_console_page.trial", "Trial")}
              value={String(revenue.trial_tenants)}
              sub={t(
                "admin_console_page.pending_conversion",
                "Pending conversion",
              )}
            />
            <KpiCard
              icon={Wallet}
              tone="info"
              title={t("admin_console_page.mrr", "MRR")}
              value={formatETB(revenue.mrr_cents)}
              sub={t("admin_console_page.this_month", "This month")}
            />
            <KpiCard
              icon={Activity}
              tone="primary"
              title={t("admin_console_page.conversion", "Conversion")}
              value={`${revenue.conversion_rate}%`}
              sub={t("admin_console_page.trial_to_paid", "Trial → paid")}
            />
          </div>
        ) : null}

        {/* ── Tenant distribution bar ────────────────────────────────── */}
        {revenue && revenue.total_tenants > 0 && (
          <TenantDistribution
            active={revenue.active_tenants}
            trial={revenue.trial_tenants}
            suspended={revenue.suspended_tenants}
            cancelled={revenue.cancelled_tenants}
          />
        )}

        {/* ── System health + Revenue trend ──────────────────────────── */}
        <div className="grid gap-6 lg:grid-cols-2">
          {/* System Health */}
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-3">
              <CardTitle className="flex items-center gap-2 text-base">
                <Server className="h-4 w-4" />
                {t("admin_console_page.system_health", "System Health")}
                <LivePulse />
              </CardTitle>
              <div className="flex items-center gap-2">
                {healthUpdatedAt > 0 && (
                  <span className="text-[10px] tabular-nums text-muted-foreground">
                    {timeAgo(new Date(healthUpdatedAt).toISOString(), t)}
                  </span>
                )}
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 w-7 p-0"
                  // Icon-only: the glyph is decorative to a screen reader, so
                  // without this the control announces as an unnamed "button".
                  aria-label={t(
                    "admin_console_page.refresh_health",
                    "Refresh system health",
                  )}
                  onClick={() =>
                    queryClient.invalidateQueries({
                      queryKey: ["admin", "health"],
                    })
                  }
                >
                  <RefreshCw className="h-3 w-3" />
                </Button>
              </div>
            </CardHeader>
            <CardContent>
              {healthLoading ? (
                <Skeleton className="h-40 w-full" />
              ) : healthError ? (
                <ErrorCard
                  message={t(
                    "admin_console_page.health_load_failed",
                    "Couldn't load system health",
                  )}
                  onRetry={() => refetchHealth()}
                />
              ) : health ? (
                <div className="space-y-4">
                  {/* Services */}
                  <div className="space-y-2">
                    {Object.entries(health.services).map(([name, svc]) => (
                      <div
                        key={name}
                        className="flex items-center justify-between gap-3"
                      >
                        <div className="flex items-center gap-2">
                          <ServiceIcon name={name} />
                          <span className="text-sm capitalize text-foreground">
                            {name}
                          </span>
                        </div>
                        <div className="flex items-center gap-2">
                          {svc.response_ms !== undefined &&
                            svc.response_ms > 0 && (
                              <span
                                className={cn(
                                  "text-xs tabular-nums",
                                  svc.response_ms > 500
                                    ? "text-status-warning"
                                    : "text-muted-foreground",
                                )}
                              >
                                {svc.response_ms}ms
                              </span>
                            )}
                          <HealthDot status={svc.status} />
                        </div>
                      </div>
                    ))}
                  </div>

                  {/* Queue depths */}
                  {health.queue && Object.keys(health.queue).length > 0 && (
                    <div className="border-t pt-3">
                      <p className="mb-2 text-xs font-medium uppercase tracking-wider text-muted-foreground">
                        {t("admin_console_page.queues", "Queues")}
                      </p>
                      <div className="flex flex-wrap gap-2">
                        {Object.entries(health.queue).map(([q, info]) => (
                          <div
                            key={q}
                            className="flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                          >
                            <span className="font-medium text-foreground">
                              {q}
                            </span>
                            <span
                              className={cn(
                                "tabular-nums",
                                (info.depth ?? 0) > 50
                                  ? "font-semibold text-status-warning"
                                  : "text-muted-foreground",
                              )}
                            >
                              {info.depth ?? "?"}
                            </span>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}

                  {/* Resources */}
                  {health.resources && (
                    <div className="border-t pt-3">
                      <p className="mb-2 text-xs font-medium uppercase tracking-wider text-muted-foreground">
                        {t("admin_console_page.resources", "Resources")}
                      </p>
                      <div className="space-y-3">
                        <ResourceGauge
                          label={t("admin_console_page.memory", "Memory")}
                          current={health.resources.php_memory_mb}
                          peak={health.resources.php_peak_memory_mb}
                          unit="MB"
                        />
                        {health.resources.disk_free_gb !== null && (
                          <ResourceGauge
                            label={t(
                              "admin_console_page.disk_free",
                              "Disk Free",
                            )}
                            current={health.resources.disk_free_gb}
                            unit="GB"
                            warn={health.resources.disk_free_gb < 5}
                          />
                        )}
                      </div>
                    </div>
                  )}

                  {/* Failed jobs summary */}
                  <div className="flex items-center justify-between border-t pt-3">
                    <span className="text-sm text-foreground">
                      {t("admin_console_page.failed_jobs", "Failed Jobs")}
                    </span>
                    <span
                      className={cn(
                        "text-sm font-semibold tabular-nums",
                        failedJobCount > 0
                          ? "text-status-error"
                          : "text-status-success",
                      )}
                    >
                      {failedJobCount}
                    </span>
                  </div>
                </div>
              ) : null}
            </CardContent>
          </Card>

          {/* Revenue trend — visual bar chart */}
          {revenue?.monthly_trend && (
            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-base">
                  <TrendingUp className="h-4 w-4" />
                  {t(
                    "admin_console_page.monthly_revenue_trend",
                    "Revenue Trend",
                  )}
                </CardTitle>
              </CardHeader>
              <CardContent>
                <RevenueBars data={revenue.monthly_trend} />
              </CardContent>
            </Card>
          )}
        </div>

        {/* ── Failed Jobs (only when present) ────────────────────────── */}
        {failedJobsData && failedJobsData.data.length > 0 && (
          <Card className="border-status-error/30">
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle className="flex items-center gap-2 text-base">
                <AlertTriangle className="h-4 w-4 text-status-error" />
                {t("admin_console_page.failed_jobs", "Failed Jobs")} (
                {failedJobsData.meta?.total ?? failedJobsData.data.length})
              </CardTitle>
              <Button
                variant="outline"
                size="sm"
                onClick={() =>
                  retryAll.mutate(undefined, {
                    onSuccess: (d: { message?: string }) =>
                      toast.success(
                        d.message ??
                          t(
                            "admin_console_page.jobs_retried",
                            "All jobs retried",
                          ),
                      ),
                    onError: () =>
                      toast.error(
                        t("admin_console_page.retry_failed", "Retry failed"),
                      ),
                  })
                }
                disabled={retryAll.isPending}
              >
                {retryAll.isPending ? (
                  <Loader2 className="mr-1 h-3 w-3 animate-spin" />
                ) : (
                  <RefreshCw className="mr-1 h-3 w-3" />
                )}
                {t("admin_console_page.retry_all", "Retry All")}
              </Button>
            </CardHeader>
            <CardContent>
              <div className="space-y-2">
                {failedJobsData.data.slice(0, 10).map((job) => {
                  const jobName = (() => {
                    try {
                      const parsed = JSON.parse(job.payload);
                      return (
                        (parsed.displayName ?? "Unknown").split("\\").pop() ??
                        "Unknown"
                      );
                    } catch {
                      return "Unknown";
                    }
                  })();
                  return (
                    <div
                      key={job.uuid}
                      className="flex items-center justify-between gap-3 rounded-lg border p-2.5"
                    >
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium">
                          {jobName}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          <Badge
                            variant="outline"
                            className="mr-1.5 text-[10px]"
                          >
                            {job.queue}
                          </Badge>
                          {timeAgo(job.failed_at, t)}
                        </p>
                      </div>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                          retryJob.mutate(job.uuid, {
                            onSuccess: () =>
                              toast.success(
                                t(
                                  "admin_console_page.job_retried",
                                  "Job retried",
                                ),
                              ),
                            onError: () =>
                              toast.error(
                                t(
                                  "admin_console_page.retry_failed",
                                  "Retry failed",
                                ),
                              ),
                          })
                        }
                        disabled={retryJob.isPending}
                        title={t("admin_console_page.retry_job", "Retry job")}
                      >
                        <RefreshCw className="h-3 w-3" />
                        <span className="sr-only">
                          {t("admin_console_page.retry_job", "Retry job")}
                        </span>
                      </Button>
                      {/* Dismiss, for the jobs that can never succeed. Without
                          it the alert counter only ever grew and the banner
                          stopped meaning anything. */}
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setDismissTarget(job.uuid)}
                        disabled={dismissJob.isPending}
                        title={t(
                          "admin_console_page.dismiss_job",
                          "Dismiss job",
                        )}
                      >
                        <Trash2 className="h-3 w-3 text-destructive" />
                        <span className="sr-only">
                          {t("admin_console_page.dismiss_job", "Dismiss job")}
                        </span>
                      </Button>
                    </div>
                  );
                })}
              </div>
            </CardContent>
          </Card>
        )}

        {/* Dismissal is irreversible — the row is deleted, and only the audit
            entry survives — so it goes through the app's ConfirmDialog rather
            than firing on a single stray click next to Retry. */}
        <ConfirmDialog
          open={dismissTarget !== null}
          onOpenChange={(open) => !open && setDismissTarget(null)}
          title={t(
            "admin_console_page.dismiss_job_title",
            "Dismiss failed job",
          )}
          description={t(
            "admin_console_page.dismiss_job_desc",
            "The job is deleted without running again. This cannot be undone; only the audit log will record that it existed.",
          )}
          confirmLabel={t("admin_console_page.dismiss_job", "Dismiss job")}
          variant="destructive"
          loading={dismissJob.isPending}
          onConfirm={() => {
            if (!dismissTarget) return;
            dismissJob.mutate(dismissTarget, {
              onSuccess: () =>
                toast.success(
                  t("admin_console_page.job_dismissed", "Job dismissed"),
                ),
              onError: () =>
                toast.error(
                  t("admin_console_page.dismiss_failed", "Dismiss failed"),
                ),
            });
            setDismissTarget(null);
          }}
        />

        {/* ── Platform audit activity ────────────────────────────────── */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle className="flex items-center gap-2 text-base">
              <ScrollText className="h-4 w-4" />
              {t("admin_console_page.recent_activity", "Recent Activity")}
            </CardTitle>
            {/* The card shows the latest 15 with no filters; the full trail,
                which is what an investigation actually needs, lives here. */}
            <Button variant="ghost" size="sm" asChild>
              <Link href="/admin/audit">
                {t("admin_console_page.view_all", "View all")}
                <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
              </Link>
            </Button>
          </CardHeader>
          <CardContent>
            {!audit ? (
              <Skeleton className="h-32 w-full" />
            ) : audit.data.length === 0 ? (
              <p className="py-8 text-center text-sm text-muted-foreground">
                {t(
                  "admin_console_page.no_recent_activity",
                  "No recent activity",
                )}
              </p>
            ) : (
              <div className="space-y-1.5">
                {audit.data.slice(0, 15).map((log, index) => (
                  <div
                    key={`${log.created_at}-${log.action}-${index}`}
                    className="flex items-center justify-between gap-3 rounded-lg px-2.5 py-2 transition-colors hover:bg-muted/50"
                  >
                    <div className="flex min-w-0 items-center gap-2.5">
                      <CircleDot className="h-3 w-3 shrink-0 text-muted-foreground/50" />
                      <span className="truncate text-sm text-foreground">
                        {formatAction(log.action)}
                      </span>
                      {log.auditable_type && (
                        <Badge
                          variant="outline"
                          className="shrink-0 text-[10px] font-mono"
                        >
                          {log.auditable_type.split("\\").pop()}
                        </Badge>
                      )}
                    </div>
                    <span className="shrink-0 text-xs tabular-nums text-muted-foreground">
                      {timeAgo(log.created_at, t)}
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

// ── Sub-components ──────────────────────────────────────────────────────────

function ErrorCard({
  message,
  onRetry,
}: {
  message: string;
  onRetry: () => void;
}) {
  const { t } = useT();
  return (
    <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed py-8 text-center">
      <AlertTriangle className="h-6 w-6 text-status-error" />
      <p className="text-sm font-medium text-foreground">{message}</p>
      <Button variant="outline" size="sm" onClick={onRetry}>
        <RefreshCw className="mr-1.5 h-3 w-3" />
        {t("common.retry", "Try again")}
      </Button>
    </div>
  );
}

function LivePulse() {
  return (
    <span className="relative ml-1 flex h-2 w-2">
      <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-status-success opacity-40" />
      <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
    </span>
  );
}

function HealthDot({ status }: { status: string }) {
  const { t } = useT();
  const statusLabels: Record<string, string> = {
    healthy: t("admin.status.healthy", "Healthy"),
    unhealthy: t("admin.status.unhealthy", "Unhealthy"),
    unknown: t("admin.status.unknown", "Unknown"),
  };
  return (
    <div className="flex items-center gap-1.5">
      <div
        className={cn(
          "h-2 w-2 rounded-full",
          status === "healthy" && "bg-status-success",
          status === "unhealthy" && "bg-status-error",
          status === "unknown" && "bg-muted-foreground/40",
        )}
      />
      <span
        className={cn(
          "text-xs",
          status === "healthy" && "text-status-success",
          status === "unhealthy" && "text-status-error",
          status === "unknown" && "text-muted-foreground",
        )}
      >
        {statusLabels[status] ?? status}
      </span>
    </div>
  );
}

function ServiceIcon({ name }: { name: string }) {
  const iconMap: Record<string, React.ComponentType<{ className?: string }>> = {
    api: Server,
    database: Database,
    redis: Gauge,
    storage: HardDrive,
    reverb: Radio,
  };
  const Icon = iconMap[name] ?? CheckCircle2;
  return <Icon className="h-3.5 w-3.5 text-muted-foreground" />;
}

function ResourceGauge({
  label,
  current,
  peak,
  unit,
  warn,
}: {
  label: string;
  current: number;
  peak?: number;
  unit: string;
  warn?: boolean;
}) {
  const ratio = peak ? (current / peak) * 100 : null;

  return (
    <div className="space-y-1">
      <div className="flex items-center justify-between">
        <span className="text-xs text-muted-foreground">{label}</span>
        <span
          className={cn(
            "text-xs font-semibold tabular-nums",
            warn ? "text-status-warning" : "text-foreground",
          )}
        >
          {current} {unit}
          {peak && (
            <span className="ml-1 font-normal text-muted-foreground">
              / {peak} {unit}
            </span>
          )}
        </span>
      </div>
      {ratio !== null && (
        <div className="h-1.5 overflow-hidden rounded-full bg-muted">
          <div
            className={cn(
              "h-full rounded-full transition-all duration-500",
              ratio > 85
                ? "bg-status-error"
                : ratio > 65
                  ? "bg-status-warning"
                  : "bg-status-success",
            )}
            style={{ width: `${Math.min(ratio, 100)}%` }}
          />
        </div>
      )}
    </div>
  );
}

function TenantDistribution({
  active,
  trial,
  suspended,
  cancelled,
}: {
  active: number;
  trial: number;
  suspended: number;
  cancelled: number;
}) {
  const { t } = useT();
  const total = active + trial + suspended + cancelled;
  if (total === 0) return null;

  const segments = [
    {
      key: "active",
      count: active,
      pct: Math.round((active / total) * 100),
      color: "bg-status-success",
      label: t("admin.tenant.active", "Active"),
    },
    {
      key: "trial",
      count: trial,
      pct: Math.round((trial / total) * 100),
      color: "bg-status-warning",
      label: t("admin.tenant.trial", "Trial"),
    },
    {
      key: "suspended",
      count: suspended,
      pct: Math.round((suspended / total) * 100),
      color: "bg-status-error",
      label: t("admin.tenant.suspended", "Suspended"),
    },
    {
      key: "cancelled",
      count: cancelled,
      pct: Math.round((cancelled / total) * 100),
      color: "bg-muted-foreground/40",
      label: t("admin.tenant.cancelled", "Cancelled"),
    },
  ].filter((s) => s.count > 0);

  return (
    <Card>
      <CardContent className="py-4">
        {/* Stacked bar */}
        <div className="flex h-3 overflow-hidden rounded-full">
          {segments.map((seg) => (
            <div
              key={seg.key}
              className={cn("transition-all duration-500", seg.color)}
              style={{ width: `${Math.max(seg.pct, 2)}%` }}
              title={`${seg.label}: ${seg.count} (${seg.pct}%)`}
            />
          ))}
        </div>
        {/* Legend */}
        <div className="mt-3 flex flex-wrap gap-x-5 gap-y-1">
          {segments.map((seg) => (
            <div key={seg.key} className="flex items-center gap-1.5">
              <div className={cn("h-2.5 w-2.5 rounded-sm", seg.color)} />
              <span className="text-xs text-muted-foreground">
                {seg.label}{" "}
                <span className="font-semibold tabular-nums text-foreground">
                  {seg.count}
                </span>{" "}
                {/* No /60 opacity: at text-xs that renders 2.29:1 against the
                    card, failing WCAG AA (4.5:1). The parent's undimmed
                    muted-foreground already passes and reads as secondary. */}
                <span className="text-muted-foreground">({seg.pct}%)</span>
              </span>
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}

function RevenueBars({
  data,
}: {
  data: Array<{ month: string; revenue_cents: number }>;
}) {
  const recent = data.slice(-6);
  const max = Math.max(...recent.map((m) => m.revenue_cents), 1);
  const [hoveredIdx, setHoveredIdx] = useState<number | null>(null);

  return (
    <div className="space-y-4">
      {/* Bars */}
      <div className="flex items-end gap-2" style={{ height: 160 }}>
        {recent.map((m, idx) => {
          const pct = (m.revenue_cents / max) * 100;
          const isCurrentMonth =
            m.month === new Date().toISOString().slice(0, 7);
          const isHovered = hoveredIdx === idx;
          return (
            <div
              key={m.month}
              className="group relative flex flex-1 flex-col items-center"
              style={{ height: "100%" }}
              onMouseEnter={() => setHoveredIdx(idx)}
              onMouseLeave={() => setHoveredIdx(null)}
            >
              {/* Hover tooltip */}
              {isHovered && m.revenue_cents > 0 && (
                <div className="pointer-events-none absolute -top-8 z-10 whitespace-nowrap rounded-md bg-foreground px-2 py-1 text-[10px] font-semibold tabular-nums text-background shadow-lg">
                  {formatETB(m.revenue_cents)}
                </div>
              )}
              <div className="flex w-full flex-1 items-end">
                <div
                  className={cn(
                    "w-full rounded-t-md transition-all duration-300",
                    isCurrentMonth
                      ? "bg-interactive-primary"
                      : "bg-interactive-primary/40",
                    isHovered && "opacity-80",
                  )}
                  style={{ height: `${Math.max(pct, 2)}%` }}
                />
              </div>
            </div>
          );
        })}
      </div>

      {/* Labels */}
      <div className="flex gap-2">
        {recent.map((m) => {
          const label = m.month.slice(5);
          const isCurrentMonth =
            m.month === new Date().toISOString().slice(0, 7);
          return (
            <div key={m.month} className="flex-1 text-center">
              <p
                className={cn(
                  "text-[10px]",
                  isCurrentMonth
                    ? "font-semibold text-foreground"
                    : "text-muted-foreground",
                )}
              >
                {label}
              </p>
              <p className="text-[10px] tabular-nums text-muted-foreground">
                {m.revenue_cents > 0 ? formatETB(m.revenue_cents) : "—"}
              </p>
            </div>
          );
        })}
      </div>
    </div>
  );
}
