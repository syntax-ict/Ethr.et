"use client";

import { use, useState } from "react";
import Link from "next/link";
import {
  ArrowLeft,
  Users,
  Calendar,
  Globe,
  Building2,
  Loader2,
  Pause,
  Play,
  XCircle,
  CalendarPlus,
  KeySquare,
  AlertTriangle,
  HardDrive,
  Receipt,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  DialogDescription,
} from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { StatusBadge } from "@/components/shared/status-badge";
import { RoleGate } from "@/components/shared/role-gate";
import {
  useAdminTenant,
  useUpdateTenantStatus,
  useExtendTrial,
  useImpersonateTenant,
  useTenantBackup,
} from "@/features/admin/api";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

export default function AdminTenantDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { t } = useT();
  const { id } = use(params);
  const { data: tenant, isLoading } = useAdminTenant(id);
  const updateStatus = useUpdateTenantStatus();
  const extendTrial = useExtendTrial();
  const impersonate = useImpersonateTenant();
  const backup = useTenantBackup();

  const [extendOpen, setExtendOpen] = useState(false);
  const [extendDays, setExtendDays] = useState(30);
  const [impersonateOpen, setImpersonateOpen] = useState(false);
  const [impersonationResult, setImpersonationResult] = useState<{
    token: string;
    tenant: string;
    expires_at: string;
  } | null>(null);

  function handleStatusChange(newStatus: "active" | "suspended" | "cancelled") {
    if (!tenant) return;
    const action =
      newStatus === "active"
        ? t("admin_tenant_detail_page.activate")
        : newStatus === "suspended"
          ? t("admin_tenant_detail_page.suspend")
          : t("admin_tenant_detail_page.cancel");
    if (
      !confirm(
        `${action} ${t("admin_tenant_detail_page.tenant_lc")} "${tenant.name}"?`,
      )
    )
      return;
    updateStatus.mutate(
      { publicId: tenant.public_id, status: newStatus },
      {
        onSuccess: () =>
          toast.success(
            `${t("admin_tenant_detail_page.tenant_cap")} ${newStatus}`,
          ),
        onError: () =>
          toast.error(t("admin_tenant_detail_page.status_update_failed")),
      },
    );
  }

  function handleExtendTrial(e: React.FormEvent) {
    e.preventDefault();
    if (!tenant) return;
    extendTrial.mutate(
      { publicId: tenant.public_id, days: extendDays },
      {
        onSuccess: () => {
          toast.success(
            `${t("admin_tenant_detail_page.trial_extended_prefix")} ${extendDays} ${t("admin_tenant_detail_page.days")}`,
          );
          setExtendOpen(false);
        },
        onError: () => toast.error(t("admin_tenant_detail_page.extend_failed")),
      },
    );
  }

  function handleImpersonate() {
    if (!tenant) return;
    impersonate.mutate(tenant.public_id, {
      onSuccess: (data) => {
        setImpersonationResult(data);
        setImpersonateOpen(true);
      },
      onError: (err: unknown) => {
        const axiosErr = err as { response?: { data?: { detail?: string } } };
        toast.error(
          axiosErr.response?.data?.detail ||
            t("admin_tenant_detail_page.impersonation_failed"),
        );
      },
    });
  }

  function copyToken() {
    if (impersonationResult) {
      navigator.clipboard.writeText(impersonationResult.token);
      toast.success(t("admin_tenant_detail_page.token_copied"));
    }
  }

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-32 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (!tenant) {
    return (
      <div className="py-16 text-center">
        <p className="text-muted-foreground">
          {t("admin_tenant_detail_page.not_found")}
        </p>
      </div>
    );
  }

  const isActive = tenant.status === "active";
  const isSuspended = tenant.status === "suspended";
  const isCancelled = tenant.status === "cancelled";
  const isTrial = tenant.status === "trial";

  return (
    <RoleGate allowedRoles={["super_admin"]}>
      <div className="space-y-6">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="sm" asChild>
            <Link href="/admin/tenants">
              <ArrowLeft className="mr-2 h-4 w-4" />{" "}
              {t("admin_tenant_detail_page.back_to_tenants")}
            </Link>
          </Button>
        </div>

        <PageHeader
          title={tenant.name}
          description={`${tenant.subdomain}.ethr.et`}
          actions={<StatusBadge status={tenant.status} />}
        />

        {/* Quick stats */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            icon={Users}
            label={t("payroll_detail_page.employees")}
            value={String(tenant.employee_count)}
          />
          <StatCard
            icon={Globe}
            label={t("admin_tenants_page.subdomain")}
            value={tenant.subdomain}
          />
          <StatCard
            icon={Calendar}
            label={t("admin_tenants_page.trial_ends")}
            value={
              tenant.trial_ends_at
                ? new Date(tenant.trial_ends_at).toLocaleDateString()
                : "—"
            }
          />
          <StatCard
            icon={Building2}
            label={t("admin_tenant_detail_page.type")}
            value={tenant.type ?? t("admin_tenant_detail_page.unspecified")}
          />
        </div>

        {/* Actions */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t("admin_tenant_detail_page.admin_actions")}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              {/* Status actions */}
              {!isActive && !isCancelled && (
                <Button
                  variant="outline"
                  onClick={() => handleStatusChange("active")}
                  disabled={updateStatus.isPending}
                >
                  <Play className="mr-2 h-4 w-4 text-green-600" />{" "}
                  {t("admin_tenant_detail_page.activate")}
                </Button>
              )}
              {!isSuspended && !isCancelled && (
                <Button
                  variant="outline"
                  onClick={() => handleStatusChange("suspended")}
                  disabled={updateStatus.isPending}
                >
                  <Pause className="mr-2 h-4 w-4 text-amber-600" />{" "}
                  {t("admin_tenant_detail_page.suspend")}
                </Button>
              )}
              {!isCancelled && (
                <Button
                  variant="outline"
                  onClick={() => handleStatusChange("cancelled")}
                  disabled={updateStatus.isPending}
                >
                  <XCircle className="mr-2 h-4 w-4 text-red-600" />{" "}
                  {t("admin_tenant_detail_page.cancel")}
                </Button>
              )}

              <Button variant="outline" onClick={() => setExtendOpen(true)}>
                <CalendarPlus className="mr-2 h-4 w-4 text-blue-600" />{" "}
                {t("admin_tenant_detail_page.extend_trial")}
              </Button>

              <Button
                variant="outline"
                onClick={handleImpersonate}
                disabled={impersonate.isPending}
              >
                {impersonate.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <KeySquare className="mr-2 h-4 w-4 text-purple-600" />
                )}
                {t("admin_tenant_detail_page.impersonate_admin")}
              </Button>

              <Button
                variant="outline"
                onClick={() =>
                  backup.mutate(tenant.public_id, {
                    onSuccess: () =>
                      toast.success(
                        t("admin_tenant_detail_page.backup_queued"),
                      ),
                    onError: () =>
                      toast.error(t("admin_tenant_detail_page.backup_failed")),
                  })
                }
                disabled={backup.isPending}
              >
                {backup.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <HardDrive className="mr-2 h-4 w-4 text-gray-600" />
                )}
                {t("admin_tenant_detail_page.backup_data")}
              </Button>
            </div>

            {isCancelled && (
              <div className="mt-4 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-900 dark:bg-red-950/30">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
                <p className="text-sm text-foreground">
                  {t("admin_tenant_detail_page.cancelled_notice_prefix")}{" "}
                  <span className="font-semibold">
                    {t("admin_console_page.cancelled").toLowerCase()}
                  </span>
                  . {t("admin_tenant_detail_page.cancelled_notice_suffix")}
                </p>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Profile */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t("admin_tenant_detail_page.tenant_profile")}
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            <Row
              label={t("admin_tenant_detail_page.public_id")}
              value={
                <code className="font-mono text-xs">{tenant.public_id}</code>
              }
            />
            <Row label={t("common.name")} value={tenant.name} />
            <Row
              label={t("admin_tenants_page.subdomain")}
              value={`${tenant.subdomain}.ethr.et`}
            />
            <Row
              label={t("admin_tenant_detail_page.type")}
              value={tenant.type ?? "—"}
            />
            <Row
              label={t("common.status")}
              value={<StatusBadge status={tenant.status} />}
            />
            <Row
              label={t("payroll_detail_page.employees")}
              value={String(tenant.employee_count)}
            />
            <Row
              label={t("admin_tenants_page.trial_ends")}
              value={
                tenant.trial_ends_at
                  ? new Date(tenant.trial_ends_at).toLocaleString()
                  : "—"
              }
            />
            <Row
              label={t("attendance.kiosks_page.created")}
              value={new Date(tenant.created_at).toLocaleString()}
            />
            <Row
              label={t("admin_tenant_detail_page.updated")}
              value={new Date(tenant.updated_at).toLocaleString()}
            />
          </CardContent>
        </Card>

        {/* Usage + Subscription */}
        {(tenant.usage || tenant.subscription) && (
          <div className="grid gap-4 sm:grid-cols-2">
            {tenant.usage && (
              <Card>
                <CardHeader>
                  <CardTitle className="text-base">
                    {t("admin_tenant_detail_page.usage")}
                  </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                  <Row
                    label={t("payroll_detail_page.employees")}
                    value={String(tenant.usage.employees)}
                  />
                  <Row
                    label={t("admin_tenant_detail_page.devices")}
                    value={String(tenant.usage.devices)}
                  />
                </CardContent>
              </Card>
            )}
            {tenant.subscription && (
              <Card>
                <CardHeader>
                  <CardTitle className="text-base">
                    {t("admin_tenant_detail_page.subscription")}
                  </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                  <Row
                    label={t("admin_tenant_detail_page.plan")}
                    value={tenant.subscription.plan_name ?? "—"}
                  />
                  <Row
                    label={t("common.status")}
                    value={tenant.subscription.status ?? "—"}
                  />
                  <Row
                    label={t("admin_tenant_detail_page.renews")}
                    value={
                      tenant.subscription.current_period_end
                        ? new Date(
                            tenant.subscription.current_period_end,
                          ).toLocaleDateString()
                        : "—"
                    }
                  />
                </CardContent>
              </Card>
            )}
          </div>
        )}

        {/* Invoice history */}
        {tenant.invoices && tenant.invoices.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base flex items-center gap-2">
                <Receipt className="h-4 w-4" />{" "}
                {t("admin_tenant_detail_page.invoices")}
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="text-xs text-muted-foreground">
                    <tr>
                      <th className="pb-2 text-left">ID</th>
                      <th className="pb-2 text-left">
                        {t("payroll_page.loans_page.amount")}
                      </th>
                      <th className="pb-2 text-left">{t("common.status")}</th>
                      <th className="pb-2 text-left">
                        {t("admin_tenant_detail_page.due")}
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {tenant.invoices.map((inv) => (
                      <tr key={inv.public_id}>
                        <td className="py-2 font-mono text-xs">
                          {inv.public_id.slice(-8)}
                        </td>
                        <td className="py-2">
                          {(inv.total_cents / 100).toLocaleString()} ETB
                        </td>
                        <td className="py-2 capitalize">{inv.status}</td>
                        <td className="py-2">{inv.due_date ?? "—"}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Recent audit log */}
        {tenant.audit_log && tenant.audit_log.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">
                {t("admin_tenant_detail_page.recent_activity")}
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-1 max-h-48 overflow-y-auto">
                {tenant.audit_log.map((log, i) => (
                  <div
                    key={i}
                    className="flex items-center justify-between py-1 text-xs border-b last:border-0"
                  >
                    <code className="text-muted-foreground">{log.action}</code>
                    <span className="text-muted-foreground">
                      {new Date(log.created_at).toLocaleString()}
                    </span>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        )}

        {/* Extend Trial dialog */}
        <Dialog open={extendOpen} onOpenChange={setExtendOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>
                {t("admin_tenant_detail_page.extend_trial_period")}
              </DialogTitle>
              <DialogDescription>
                {t("admin_tenant_detail_page.current_trial_ends")}:{" "}
                <span className="font-medium">
                  {tenant.trial_ends_at
                    ? new Date(tenant.trial_ends_at).toLocaleDateString()
                    : t("admin_tenant_detail_page.no_trial_set")}
                </span>
              </DialogDescription>
            </DialogHeader>
            <form onSubmit={handleExtendTrial} className="space-y-4">
              <div>
                <Label>{t("admin_tenant_detail_page.additional_days")}</Label>
                <Input
                  type="number"
                  min={1}
                  max={180}
                  value={extendDays}
                  onChange={(e) =>
                    setExtendDays(parseInt(e.target.value) || 30)
                  }
                  className="mt-1"
                  required
                />
                <p className="mt-1 text-xs text-muted-foreground">
                  {t("admin_tenant_detail_page.max_180_days")}
                </p>
              </div>
              <DialogFooter>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setExtendOpen(false)}
                >
                  {t("common.cancel")}
                </Button>
                <Button type="submit" disabled={extendTrial.isPending}>
                  {extendTrial.isPending && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  {t("admin_tenant_detail_page.extend")}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>

        {/* Impersonation result dialog */}
        <Dialog
          open={impersonateOpen}
          onOpenChange={(open) => {
            setImpersonateOpen(open);
            if (!open) setImpersonationResult(null);
          }}
        >
          <DialogContent className="max-w-lg">
            <DialogHeader>
              <DialogTitle>
                {t("admin_tenant_detail_page.token_issued")}
              </DialogTitle>
              <DialogDescription>
                {t("admin_tenant_detail_page.act_as_admin_prefix")}{" "}
                <span className="font-medium">
                  {impersonationResult?.tenant}
                </span>
                .
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-3">
              <div className="rounded-lg border-2 border-amber-300 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950/30">
                <div className="flex items-start gap-2">
                  <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                  <div>
                    <p className="text-sm font-semibold text-foreground">
                      {t("admin_tenant_detail_page.audit_logged_notice")}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                      {t("admin_tenant_detail_page.token_expires")}:{" "}
                      {impersonationResult &&
                        new Date(
                          impersonationResult.expires_at,
                        ).toLocaleString()}
                    </p>
                  </div>
                </div>
              </div>
              <div>
                <Label className="text-xs">
                  {t("admin_tenant_detail_page.bearer_token")}
                </Label>
                <div className="mt-1 flex gap-2">
                  <code className="flex-1 rounded bg-background px-3 py-2 text-xs font-mono break-all border">
                    {impersonationResult?.token}
                  </code>
                </div>
                <Button
                  size="sm"
                  variant="outline"
                  className="mt-2"
                  onClick={copyToken}
                >
                  {t("admin_tenant_detail_page.copy_token")}
                </Button>
              </div>
              <p className="text-xs text-muted-foreground">
                {t("admin_tenant_detail_page.token_usage_hint_1")}{" "}
                <code>Authorization: Bearer ...</code>{" "}
                {t("admin_tenant_detail_page.token_usage_hint_2")}
              </p>
            </div>
            <DialogFooter>
              <Button onClick={() => setImpersonateOpen(false)}>
                {t("attendance.kiosks_page.done")}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}

function StatCard({
  icon: Icon,
  label,
  value,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: string;
}) {
  return (
    <Card>
      <CardContent className="flex items-center gap-3 p-4">
        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
          <Icon className="h-5 w-5 text-primary" />
        </div>
        <div className="min-w-0">
          <p className="text-xs text-muted-foreground">{label}</p>
          <p className="font-semibold text-foreground truncate capitalize">
            {value}
          </p>
        </div>
      </CardContent>
    </Card>
  );
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between border-b pb-2 last:border-0 last:pb-0">
      <span className="text-sm text-muted-foreground">{label}</span>
      <span className="text-sm font-medium text-foreground">{value}</span>
    </div>
  );
}
