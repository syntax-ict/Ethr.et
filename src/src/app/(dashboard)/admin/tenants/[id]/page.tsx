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
import { SimpleTable } from "@/components/shared/simple-table";
import { StatusBadge } from "@/components/shared/status-badge";
import { RoleGate } from "@/components/shared/role-gate";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import {
  useAdminTenant,
  useUpdateTenantStatus,
  useExtendTrial,
  useImpersonateTenant,
  useTenantBackup,
} from "@/features/admin/api";
import { formatETB } from "@/lib/utils/currency";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { formatDateOnly } from "@/lib/utils/date";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

function timeAgo(dateStr: string): string {
  const diff = Date.now() - new Date(dateStr).getTime();
  const mins = Math.floor(diff / 60_000);
  if (mins < 1) return "just now";
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  const days = Math.floor(hrs / 24);
  return `${days}d ago`;
}

/**
 * The gate is the outer component so it also covers the loading, error and
 * not-found branches below — when it wrapped only the success branch, anyone
 * else got a skeleton followed by a generic "couldn't load" (the backend 403s,
 * so no data leaked) instead of Access Denied, and the request fired regardless.
 */
export default function AdminTenantDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = use(params);

  return (
    <RoleGate minRole="super_admin">
      <TenantDetail id={id} />
    </RoleGate>
  );
}

function TenantDetail({ id }: { id: string }) {
  const { t } = useT();
  // Same choice as the tenant list: the operator's own zone, so every date on
  // this page sits on one clock. `due_date` below is the exception -- it is a
  // calendar date, not an instant, and must not be converted at all.
  const { formatDate, formatDateTime } = useDateFormatters();

  const statusActionLabel = (status: string | null) =>
    status === "active"
      ? t("admin_tenant_detail_page.activate")
      : status === "suspended"
        ? t("admin_tenant_detail_page.suspend")
        : t("admin_tenant_detail_page.cancel");

  const { data: tenant, isLoading, isError, refetch } = useAdminTenant(id);
  const updateStatus = useUpdateTenantStatus();
  const extendTrial = useExtendTrial();
  const impersonate = useImpersonateTenant();
  const backup = useTenantBackup();

  // Clock pinned at mount so the render stays pure (see trialDaysLeft below).
  const [mountedAt] = useState(() => Date.now());

  const [extendOpen, setExtendOpen] = useState(false);
  const [extendDays, setExtendDays] = useState(30);
  const [mfaDialogOpen, setMfaDialogOpen] = useState(false);
  const [mfaCode, setMfaCode] = useState("");
  const [pendingStatus, setPendingStatus] = useState<
    "active" | "suspended" | "cancelled" | null
  >(null);

  // Cancelling a tenant is irreversible from this screen (the page says so
  // itself), and suspension cuts off everyone at that organization — so these
  // go through the app's ConfirmDialog rather than a native confirm() that
  // rides on one stray Enter keypress.
  function confirmStatusChange() {
    if (!tenant || pendingStatus === null) return;
    updateStatus.mutate(
      { publicId: tenant.public_id, status: pendingStatus },
      {
        onSuccess: () => {
          toast.success(
            `${t("admin_tenant_detail_page.tenant_cap")} ${pendingStatus}`,
          );
          setPendingStatus(null);
        },
        onError: () => {
          toast.error(t("admin_tenant_detail_page.status_update_failed"));
          setPendingStatus(null);
        },
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

  function handleConfirmImpersonate(e: React.FormEvent) {
    e.preventDefault();
    if (!tenant) return;
    impersonate.mutate(
      { publicId: tenant.public_id, code: mfaCode },
      {
        // No success branch: the mutation swaps the session and reloads into
        // the tenant's dashboard, so anything set here would never paint.
        onError: (err: unknown) => {
          const axiosErr = err as {
            response?: { data?: { detail?: string } };
          };
          toast.error(
            axiosErr.response?.data?.detail ||
              t("admin_tenant_detail_page.impersonation_failed"),
          );
        },
      },
    );
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

  if (isError) {
    return (
      <div className="flex flex-col items-center justify-center gap-2 py-16 text-center">
        <AlertTriangle className="h-8 w-8 text-status-error" />
        <p className="text-sm font-medium text-foreground">
          {t(
            "admin_tenant_detail_page.load_failed",
            "Couldn't load this tenant",
          )}
        </p>
        <Button variant="outline" size="sm" onClick={() => refetch()}>
          {t("common.retry", "Try again")}
        </Button>
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

  // `Date.now()` read straight in the render body made the render impure — the
  // same input could produce a different output, which is what
  // react-hooks/purity flags. Pinned once per mount instead: a trial countdown
  // measured in days has no reason to re-read the clock mid-render.
  const trialDaysLeft = tenant.trial_ends_at
    ? Math.ceil(
        (new Date(tenant.trial_ends_at).getTime() - mountedAt) / 86_400_000,
      )
    : null;
  const trialExpiringSoon =
    trialDaysLeft !== null && trialDaysLeft > 0 && trialDaysLeft <= 7;

  return (
    <>
      <div className="space-y-6">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="sm" asChild>
            <Link href="/admin/tenants">
              <ArrowLeft className="mr-2 h-4 w-4" />{" "}
              {t("admin_tenant_detail_page.back_to_tenants")}
            </Link>
          </Button>
        </div>

        {/* The tenant's own name, rendered here rather than left to the layout's
            title bar: that bar is driven by the URL, and this route's last
            segment is an opaque ULID. `PageHeader` only renders `actions`, so
            passing a title to it would have shown nothing. */}
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="min-w-0">
            <h1 className="truncate text-2xl font-bold tracking-tight text-foreground">
              {tenant.name}
            </h1>
            <p className="mt-0.5 font-mono text-sm text-muted-foreground">
              {tenant.subdomain}.ethr.et
            </p>
          </div>
          <StatusBadge status={tenant.status} />
        </div>

        {/* Trial expiring soon banner */}
        {trialExpiringSoon && (
          <div className="flex items-center gap-3 rounded-xl border border-status-warning/30 bg-status-warning/5 p-3">
            <AlertTriangle className="h-4 w-4 shrink-0 text-status-warning" />
            <p className="text-sm text-foreground">
              {t(
                "admin_tenant_detail_page.trial_expires_in",
                "Trial expires in",
              )}{" "}
              <span className="font-semibold">
                {trialDaysLeft} {t("admin_tenant_detail_page.days", "days")}
              </span>
            </p>
            <Button
              variant="outline"
              size="sm"
              className="ml-auto"
              onClick={() => setExtendOpen(true)}
            >
              <CalendarPlus className="mr-1 h-3 w-3" />
              {t("admin_tenant_detail_page.extend", "Extend")}
            </Button>
          </div>
        )}

        {/* Quick stats */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            icon={Users}
            label={t("payroll_detail_page.employees")}
            value={String(tenant.usage?.employees ?? 0)}
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
              tenant.trial_ends_at ? formatDate(tenant.trial_ends_at) : "—"
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
                  onClick={() => setPendingStatus("active")}
                  disabled={updateStatus.isPending}
                >
                  <Play className="mr-2 h-4 w-4 text-status-success" />{" "}
                  {t("admin_tenant_detail_page.activate")}
                </Button>
              )}
              {!isSuspended && !isCancelled && (
                <Button
                  variant="outline"
                  onClick={() => setPendingStatus("suspended")}
                  disabled={updateStatus.isPending}
                >
                  <Pause className="mr-2 h-4 w-4 text-status-warning" />{" "}
                  {t("admin_tenant_detail_page.suspend")}
                </Button>
              )}
              {!isCancelled && (
                <Button
                  variant="outline"
                  onClick={() => setPendingStatus("cancelled")}
                  disabled={updateStatus.isPending}
                >
                  <XCircle className="mr-2 h-4 w-4 text-destructive" />{" "}
                  {t("admin_tenant_detail_page.cancel")}
                </Button>
              )}

              <Button variant="outline" onClick={() => setExtendOpen(true)}>
                <CalendarPlus className="mr-2 h-4 w-4 text-status-info" />{" "}
                {t("admin_tenant_detail_page.extend_trial")}
              </Button>

              <Button
                variant="outline"
                onClick={() => setMfaDialogOpen(true)}
                disabled={impersonate.isPending}
              >
                {impersonate.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <KeySquare className="mr-2 h-4 w-4 text-primary" />
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
                  <HardDrive className="mr-2 h-4 w-4 text-muted-foreground" />
                )}
                {t("admin_tenant_detail_page.backup_data")}
              </Button>
            </div>

            {isCancelled && (
              <div className="mt-4 flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 p-3">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-destructive" />
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
              value={String(tenant.usage?.employees ?? 0)}
            />
            <Row
              label={t("admin_tenants_page.trial_ends")}
              value={
                tenant.trial_ends_at
                  ? formatDateTime(tenant.trial_ends_at)
                  : "—"
              }
            />
            <Row
              label={t("attendance.kiosks_page.created")}
              value={formatDateTime(tenant.created_at)}
            />
            <Row
              label={t("admin_tenant_detail_page.updated")}
              value={formatDateTime(tenant.updated_at)}
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
                        ? formatDate(tenant.subscription.current_period_end)
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
            <CardContent className="p-0">
              <SimpleTable
                caption={t("admin_tenant_detail_page.invoices")}
                headers={[
                  "ID",
                  t("payroll_page.loans_page.amount"),
                  t("common.status"),
                  t("admin_tenant_detail_page.due"),
                ]}
                rows={tenant.invoices.map((inv) => ({
                  key: inv.public_id,
                  cells: [
                    <span key="id" className="font-mono text-xs">
                      {inv.public_id.slice(-8)}
                    </span>,
                    <span key="a" className="tabular-nums">
                      {formatETB(inv.total_cents)}
                    </span>,
                    <span
                      key="s"
                      className={cn(
                        "inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium capitalize",
                        inv.status === "paid" &&
                          "bg-success-soft text-success-on-soft",
                        inv.status === "pending" &&
                          "bg-warning-soft text-warning-on-soft",
                        inv.status === "overdue" &&
                          "bg-destructive-soft text-destructive-on-soft",
                        !["paid", "pending", "overdue"].includes(inv.status) &&
                          "bg-muted text-muted-foreground",
                      )}
                    >
                      {inv.status}
                    </span>,
                    <span key="d" className="text-muted-foreground">
                      {/* `Invoice::$casts` types due_date as `date` -- the day the
                          invoice falls due, not a moment. It renders as
                          written rather than being shifted into a zone. */}
                      {inv.due_date ? formatDateOnly(inv.due_date) : "—"}
                    </span>,
                  ],
                }))}
              />
            </CardContent>
          </Card>
        )}

        {/* Recent audit log */}
        {tenant.audit_log && tenant.audit_log.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">
                {t(
                  "admin_tenant_detail_page.recent_activity",
                  "Recent Activity",
                )}
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="max-h-48 space-y-1 overflow-y-auto">
                {tenant.audit_log.map((log, i) => (
                  <div
                    key={i}
                    className="flex items-center justify-between gap-3 rounded-lg px-2.5 py-1.5 text-xs transition-colors hover:bg-muted/50"
                  >
                    <span className="truncate text-sm text-foreground">
                      {log.action.replace(/\./g, " › ").replace(/_/g, " ")}
                    </span>
                    <span className="shrink-0 tabular-nums text-muted-foreground">
                      {timeAgo(log.created_at)}
                    </span>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        )}

        {/* Status change confirmation */}
        <ConfirmDialog
          open={pendingStatus !== null}
          onOpenChange={(open) => {
            if (!open) setPendingStatus(null);
          }}
          title={`${statusActionLabel(pendingStatus)} ${t("admin_tenant_detail_page.tenant_lc")}`}
          description={
            pendingStatus === "cancelled"
              ? t(
                  "admin_tenant_detail_page.confirm_cancel",
                  "Cancelling disables most actions for this tenant and cannot be undone from this screen.",
                )
              : pendingStatus === "suspended"
                ? t(
                    "admin_tenant_detail_page.confirm_suspend",
                    "Suspending immediately blocks everyone at this organization from signing in.",
                  )
                : t(
                    "admin_tenant_detail_page.confirm_activate",
                    "This restores full access for everyone at this organization.",
                  )
          }
          confirmLabel={`${statusActionLabel(pendingStatus)} ${tenant.name}`}
          variant={pendingStatus === "active" ? "default" : "destructive"}
          loading={updateStatus.isPending}
          onConfirm={confirmStatusChange}
        />

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
                    ? formatDate(tenant.trial_ends_at)
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

        {/* MFA code dialog (required before impersonating) */}
        <Dialog
          open={mfaDialogOpen}
          onOpenChange={(open) => {
            setMfaDialogOpen(open);
            if (!open) setMfaCode("");
          }}
        >
          <DialogContent>
            <form onSubmit={handleConfirmImpersonate}>
              <DialogHeader>
                <DialogTitle>
                  {t("admin_tenant_detail_page.mfa_confirm_title")}
                </DialogTitle>
                <DialogDescription>
                  {t("admin_tenant_detail_page.mfa_confirm_description")}
                </DialogDescription>
              </DialogHeader>
              <div className="py-4">
                <div className="mb-4 flex items-start gap-2 rounded-lg border border-status-warning/40 bg-status-warning/5 p-3">
                  <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-status-warning" />
                  <div>
                    <p className="text-sm font-semibold text-foreground">
                      {t("admin_tenant_detail_page.audit_logged_notice")}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                      {t(
                        "admin_tenant_detail_page.impersonation_effect",
                        "You will be signed in as this tenant's admin for 30 minutes. Use the banner at the top of the screen to return to your own account.",
                      )}
                    </p>
                  </div>
                </div>
                <Label htmlFor="impersonate-mfa-code">
                  {t("admin_tenant_detail_page.mfa_code_label")}
                </Label>
                <Input
                  id="impersonate-mfa-code"
                  className="mt-1"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  maxLength={6}
                  required
                  value={mfaCode}
                  onChange={(e) => setMfaCode(e.target.value)}
                  placeholder="000000"
                />
              </div>
              <DialogFooter>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setMfaDialogOpen(false)}
                >
                  {t("common.cancel")}
                </Button>
                <Button type="submit" disabled={impersonate.isPending}>
                  {impersonate.isPending && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  {t("admin_tenant_detail_page.impersonate_admin")}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </>
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
        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-interactive-primary/8 ring-1 ring-interactive-primary/20">
          <Icon className="h-5 w-5 text-interactive-primary" />
        </div>
        <div className="min-w-0">
          <p className="text-xs text-muted-foreground">{label}</p>
          <p className="truncate font-semibold capitalize text-foreground">
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
