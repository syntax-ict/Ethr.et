"use client";

import { useState } from "react";
import {
  Receipt,
  Crown,
  CalendarClock,
  CreditCard,
  Check,
  Loader2,
  ArrowUpDown,
  AlertCircle,
  BadgeCheck,
  FileText,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  DialogDescription,
} from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { StatusBadge } from "@/components/shared/status-badge";
import { CurrencyDisplay } from "@/components/shared/currency-display";
import { RoleGate } from "@/components/shared/role-gate";
import {
  useBillingDashboard,
  usePlans,
  useChangePlan,
  useMarkInvoicePaid,
  type Plan,
  type BillingInvoice,
} from "@/features/billing/api";
import { cn } from "@/lib/utils";
import { toast } from "sonner";

export default function BillingPage() {
  const { data: dashboard, isLoading } = useBillingDashboard();
  const [planDialogOpen, setPlanDialogOpen] = useState(false);

  return (
    <RoleGate minRole="tenant_admin">
      <div className="space-y-6">
        <PageHeader
          title="Billing"
          description="Manage your subscription, invoices, and payment history"
          actions={
            <Button onClick={() => setPlanDialogOpen(true)}>
              <ArrowUpDown className="mr-2 h-4 w-4" /> Change Plan
            </Button>
          }
        />

        {isLoading ? (
          <BillingSkeleton />
        ) : (
          <>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <SummaryCard
                icon={Crown}
                color="purple"
                title="Current Plan"
                value={dashboard?.plan ?? "No plan"}
                sub={dashboard?.subscription_status ?? "—"}
              />
              <SummaryCard
                icon={CreditCard}
                color="blue"
                title="Monthly Price"
                value={
                  dashboard?.plan_price_cents
                    ? formatCents(dashboard.plan_price_cents)
                    : "—"
                }
                sub="ETB / month"
              />
              <SummaryCard
                icon={CalendarClock}
                color="amber"
                title="Next Billing"
                value={
                  dashboard?.current_period_end
                    ? new Date(
                        dashboard.current_period_end,
                      ).toLocaleDateString()
                    : "—"
                }
                sub="Period ends"
              />
              <SummaryCard
                icon={Receipt}
                color="green"
                title="Invoices"
                value={String(dashboard?.invoices?.length ?? 0)}
                sub="Recent records"
              />
            </div>

            <PaymentInstructions />

            <InvoiceHistory invoices={dashboard?.invoices ?? []} />
          </>
        )}

        <PlanChangeDialog
          open={planDialogOpen}
          onClose={() => setPlanDialogOpen(false)}
          currentPlanName={dashboard?.plan ?? null}
        />
      </div>
    </RoleGate>
  );
}

function BillingSkeleton() {
  return (
    <>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-32" />
        ))}
      </div>
      <Skeleton className="h-32 w-full" />
      <Skeleton className="h-64 w-full" />
    </>
  );
}

const colorMap: Record<string, string> = {
  purple:
    "bg-purple-100 text-purple-600 dark:bg-purple-950 dark:text-purple-400",
  blue: "bg-blue-100 text-blue-600 dark:bg-blue-950 dark:text-blue-400",
  amber: "bg-amber-100 text-amber-600 dark:bg-amber-950 dark:text-amber-400",
  green: "bg-green-100 text-green-600 dark:bg-green-950 dark:text-green-400",
};

function SummaryCard({
  icon: Icon,
  color,
  title,
  value,
  sub,
}: {
  icon: React.ComponentType<{ className?: string }>;
  color: string;
  title: string;
  value: string;
  sub: string;
}) {
  return (
    <Card>
      <CardContent className="p-5">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="text-sm text-muted-foreground">{title}</p>
            <p className="mt-1 text-lg font-bold text-foreground capitalize truncate">
              {value}
            </p>
            <p className="mt-0.5 text-xs text-muted-foreground capitalize">
              {sub}
            </p>
          </div>
          <div
            className={cn(
              "flex h-11 w-11 shrink-0 items-center justify-center rounded-xl",
              colorMap[color],
            )}
          >
            <Icon className="h-5 w-5" />
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

function PaymentInstructions() {
  return (
    <Card className="border-blue-200 bg-blue-50/50 dark:border-blue-900 dark:bg-blue-950/20">
      <CardContent className="p-5">
        <div className="flex items-start gap-3">
          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-100 dark:bg-blue-900">
            <BadgeCheck className="h-5 w-5 text-blue-600 dark:text-blue-400" />
          </div>
          <div className="flex-1">
            <p className="font-semibold text-foreground">
              Payment Instructions — Manual Bank Transfer
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
              Transfer the invoice amount to the account below and reference
              your invoice ID. Your subscription will be marked active once
              payment is verified.
            </p>
            <div className="mt-3 grid gap-3 sm:grid-cols-3">
              <div className="rounded-lg border bg-background p-3">
                <p className="text-xs text-muted-foreground">Bank</p>
                <p className="text-sm font-medium">
                  Commercial Bank of Ethiopia
                </p>
              </div>
              <div className="rounded-lg border bg-background p-3">
                <p className="text-xs text-muted-foreground">Account Number</p>
                <p className="text-sm font-mono font-medium">
                  1000 1234 5678 90
                </p>
              </div>
              <div className="rounded-lg border bg-background p-3">
                <p className="text-xs text-muted-foreground">Account Name</p>
                <p className="text-sm font-medium">ETHR Technologies PLC</p>
              </div>
            </div>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

function InvoiceHistory({ invoices }: { invoices: BillingInvoice[] }) {
  const markPaid = useMarkInvoicePaid();

  function handleMarkPaid(invoice: BillingInvoice) {
    markPaid.mutate(invoice.public_id, {
      onSuccess: () => toast.success("Invoice marked as paid"),
      onError: () => toast.error("Failed to mark invoice as paid"),
    });
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Invoice History</CardTitle>
      </CardHeader>
      <CardContent className="p-0">
        {invoices.length === 0 ? (
          <EmptyState
            icon={Receipt}
            title="No invoices yet"
            description="Invoices will appear here once your subscription is billed"
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b bg-muted/50">
                  <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                    Invoice ID
                  </th>
                  <th className="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted-foreground">
                    Amount
                  </th>
                  <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground sm:table-cell">
                    Due Date
                  </th>
                  <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground md:table-cell">
                    Paid On
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                    Status
                  </th>
                  <th className="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted-foreground">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody>
                {invoices.map((invoice) => {
                  const isOverdue =
                    invoice.status !== "paid" &&
                    invoice.due_date &&
                    new Date(invoice.due_date) < new Date();
                  return (
                    <tr
                      key={invoice.public_id}
                      className="border-b last:border-0 hover:bg-muted/30"
                    >
                      <td className="px-4 py-3 text-sm font-mono text-muted-foreground">
                        {invoice.public_id.slice(0, 10)}…
                      </td>
                      <td className="px-4 py-3 text-right">
                        <CurrencyDisplay
                          cents={invoice.total_cents}
                          className="text-sm font-semibold"
                        />
                      </td>
                      <td className="hidden px-4 py-3 text-sm sm:table-cell">
                        <span
                          className={
                            isOverdue
                              ? "text-destructive font-medium"
                              : "text-muted-foreground"
                          }
                        >
                          {invoice.due_date ?? "—"}
                          {isOverdue && (
                            <AlertCircle className="ml-1 inline h-3 w-3" />
                          )}
                        </span>
                      </td>
                      <td className="hidden px-4 py-3 text-sm text-muted-foreground md:table-cell">
                        {invoice.paid_at
                          ? new Date(invoice.paid_at).toLocaleDateString()
                          : "—"}
                      </td>
                      <td className="px-4 py-3">
                        <StatusBadge status={invoice.status} />
                      </td>
                      <td className="px-4 py-3 text-right">
                        <div className="flex justify-end gap-1">
                          {invoice.paid_at && (
                            <Button
                              variant="ghost"
                              size="sm"
                              title="Download receipt"
                            >
                              <FileText className="h-4 w-4" />
                            </Button>
                          )}
                          {invoice.status !== "paid" && (
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => handleMarkPaid(invoice)}
                              disabled={markPaid.isPending}
                            >
                              {markPaid.isPending &&
                              markPaid.variables === invoice.public_id ? (
                                <Loader2 className="h-3 w-3 animate-spin" />
                              ) : (
                                <Check className="mr-1 h-3 w-3" />
                              )}
                              Mark Paid
                            </Button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function PlanChangeDialog({
  open,
  onClose,
  currentPlanName,
}: {
  open: boolean;
  onClose: () => void;
  currentPlanName: string | null;
}) {
  const { data: plansData, isLoading } = usePlans();
  const changePlan = useChangePlan();
  const [selectedPlan, setSelectedPlan] = useState<Plan | null>(null);
  const [prorationResult, setProrationResult] = useState<{
    proration_cents: number;
    new_plan: string;
  } | null>(null);

  const plans = plansData?.data ?? [];

  function handleConfirm() {
    if (!selectedPlan) return;
    changePlan.mutate(
      { plan_public_id: selectedPlan.public_id },
      {
        onSuccess: (data) => {
          setProrationResult({
            proration_cents: data.proration_cents,
            new_plan: data.new_plan,
          });
          toast.success(`Plan changed to ${data.new_plan}`);
        },
        onError: (err: unknown) => {
          const axiosErr = err as { response?: { data?: { detail?: string } } };
          toast.error(
            axiosErr.response?.data?.detail || "Failed to change plan",
          );
        },
      },
    );
  }

  function handleClose() {
    onClose();
    setSelectedPlan(null);
    setProrationResult(null);
  }

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <DialogTitle>Change Subscription Plan</DialogTitle>
          <DialogDescription>
            {prorationResult
              ? "Your plan has been updated. Proration applied below."
              : "Select a new plan. Upgrades take effect immediately with prorated charges; downgrades apply at the end of the current period."}
          </DialogDescription>
        </DialogHeader>

        {prorationResult ? (
          <div className="space-y-4 py-4 text-center">
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-green-100 dark:bg-green-950">
              <Check className="h-7 w-7 text-green-600" />
            </div>
            <div>
              <p className="text-lg font-semibold">
                Plan changed to {prorationResult.new_plan}
              </p>
              <p className="mt-2 text-sm text-muted-foreground">
                {prorationResult.proration_cents > 0
                  ? "Prorated charge for this period:"
                  : prorationResult.proration_cents < 0
                    ? "Credit applied to next invoice:"
                    : "No proration adjustment."}
              </p>
              {prorationResult.proration_cents !== 0 && (
                <CurrencyDisplay
                  cents={Math.abs(prorationResult.proration_cents)}
                  className={cn(
                    "mt-1 text-2xl font-bold",
                    prorationResult.proration_cents > 0
                      ? "text-destructive"
                      : "text-green-600",
                  )}
                />
              )}
            </div>
            <DialogFooter className="sm:justify-center">
              <Button onClick={handleClose}>Done</Button>
            </DialogFooter>
          </div>
        ) : isLoading ? (
          <div className="grid gap-3 py-4 sm:grid-cols-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-72" />
            ))}
          </div>
        ) : (
          <>
            <div className="grid gap-3 py-4 sm:grid-cols-3">
              {plans.map((plan) => {
                const isCurrent = plan.name === currentPlanName;
                const isSelected = selectedPlan?.public_id === plan.public_id;
                return (
                  <button
                    key={plan.public_id}
                    type="button"
                    disabled={isCurrent}
                    onClick={() => setSelectedPlan(plan)}
                    className={cn(
                      "group rounded-xl border-2 p-4 text-left transition-all",
                      isCurrent
                        ? "cursor-not-allowed border-muted bg-muted/30 opacity-60"
                        : isSelected
                          ? "border-primary bg-primary/5 shadow-md"
                          : "border-border hover:border-primary/50 hover:shadow-sm",
                    )}
                  >
                    <div className="flex items-start justify-between">
                      <div>
                        <p className="font-semibold text-foreground">
                          {plan.name}
                        </p>
                        {isCurrent && (
                          <Badge variant="outline" className="mt-1 text-[10px]">
                            Current
                          </Badge>
                        )}
                      </div>
                      {isSelected && !isCurrent && (
                        <div className="flex h-5 w-5 items-center justify-center rounded-full bg-primary text-primary-foreground">
                          <Check className="h-3 w-3" />
                        </div>
                      )}
                    </div>
                    <div className="mt-3">
                      <CurrencyDisplay
                        cents={plan.price_cents}
                        className="text-2xl font-bold"
                      />
                      <p className="text-xs text-muted-foreground">per month</p>
                    </div>
                    <div className="mt-4 space-y-1.5 text-xs text-muted-foreground">
                      {plan.max_employees != null && (
                        <p>
                          👥 Up to{" "}
                          <span className="font-medium text-foreground">
                            {plan.max_employees}
                          </span>{" "}
                          employees
                        </p>
                      )}
                      {plan.max_branches != null && (
                        <p>
                          🏢 Up to{" "}
                          <span className="font-medium text-foreground">
                            {plan.max_branches}
                          </span>{" "}
                          branches
                        </p>
                      )}
                      {plan.max_devices != null && (
                        <p>
                          📱 Up to{" "}
                          <span className="font-medium text-foreground">
                            {plan.max_devices}
                          </span>{" "}
                          devices
                        </p>
                      )}
                      {plan.features && plan.features.length > 0 && (
                        <ul className="mt-2 space-y-1 border-t pt-2">
                          {plan.features.slice(0, 5).map((f) => (
                            <li key={f} className="flex items-start gap-1.5">
                              <Check className="mt-0.5 h-3 w-3 shrink-0 text-green-600" />
                              <span>{f}</span>
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                  </button>
                );
              })}
            </div>

            <DialogFooter>
              <Button variant="outline" onClick={handleClose}>
                Cancel
              </Button>
              <Button
                onClick={handleConfirm}
                disabled={!selectedPlan || changePlan.isPending}
              >
                {changePlan.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                Confirm Plan Change
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}

function formatCents(cents: number): string {
  return (
    new Intl.NumberFormat("en-ET", {
      minimumFractionDigits: 0,
      maximumFractionDigits: 2,
    }).format(cents / 100) + " ETB"
  );
}
