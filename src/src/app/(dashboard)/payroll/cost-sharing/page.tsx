"use client";

import { useState } from "react";
import { GraduationCap, Plus, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { StatusBadge } from "@/components/shared/status-badge";
import { CurrencyDisplay } from "@/components/shared/currency-display";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import { RoleGate } from "@/components/shared/role-gate";
import {
  useCostSharingList,
  useCreateCostSharing,
  useUpdateCostSharing,
  type CostSharing,
} from "@/features/payroll/api";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

const EMPTY_FORM = {
  employee_public_id: "",
  total_obligation_etb: "",
  deduction_rate_percent: "",
  started_on: "",
  notes: "",
};

export default function CostSharingPage() {
  const { t } = useT();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [form, setForm] = useState(EMPTY_FORM);

  const { data, isLoading, isError, refetch } = useCostSharingList();
  const createObligation = useCreateCostSharing();
  const updateObligation = useUpdateCostSharing();

  const obligations: CostSharing[] = data?.data ?? [];

  const toggleSuspended = (obligation: CostSharing) => {
    const next = obligation.status === "active" ? "suspended" : "active";

    updateObligation.mutate(
      { publicId: obligation.public_id, status: next },
      {
        onSuccess: () =>
          toast.success(
            t(
              "payroll_page.cost_sharing_page.status_updated",
              "Status updated",
            ),
          ),
        onError: () =>
          toast.error(
            t(
              "payroll_page.cost_sharing_page.status_update_failed",
              "Could not update status",
            ),
          ),
      },
    );
  };

  return (
    <RoleGate allowedRoles={["finance_admin", "tenant_admin", "super_admin"]}>
      <div className="space-y-6">
        <PageHeader
          title={t("payroll_page.cost_sharing_page.title")}
          description={t("payroll_page.cost_sharing_page.description")}
          actions={
            <Button onClick={() => setDialogOpen(true)}>
              <Plus className="mr-2 h-4 w-4" />{" "}
              {t("payroll_page.cost_sharing_page.new_obligation")}
            </Button>
          }
        />

        {isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-20" />
            ))}
          </div>
        ) : isError ? (
          <EmptyState
            icon={GraduationCap}
            title={t("common.error", "Something went wrong")}
            description={t(
              "payroll_page.cost_sharing_page.load_failed",
              "Could not load cost-sharing obligations",
            )}
            action={
              <Button variant="outline" onClick={() => refetch()}>
                {t("common.retry", "Retry")}
              </Button>
            }
          />
        ) : obligations.length === 0 ? (
          <EmptyState
            icon={GraduationCap}
            title={t("payroll_page.cost_sharing_page.none")}
            description={t("payroll_page.cost_sharing_page.none_desc")}
          />
        ) : (
          <Card>
            <CardContent className="p-0">
              <SimpleTable
                caption={t("payroll_page.cost_sharing_page.title")}
                headers={[
                  t("attendance.employee"),
                  t("payroll_page.cost_sharing_page.total_obligation"),
                  t("payroll_page.cost_sharing_page.repaid"),
                  t("payroll_page.cost_sharing_page.outstanding"),
                  t("payroll_page.cost_sharing_page.rate"),
                  t("common.status"),
                  "",
                ]}
                align={[
                  "left",
                  "right",
                  "right",
                  "right",
                  "right",
                  "left",
                  "right",
                ]}
                colClassName={[
                  "",
                  "hidden md:table-cell",
                  "hidden sm:table-cell",
                  "",
                  "hidden lg:table-cell",
                  "",
                  "",
                ]}
                rows={obligations.map((obligation) => ({
                  key: obligation.public_id,
                  cells: [
                    <span key="e" className="font-medium">
                      {obligation.employee?.name ?? "—"}
                    </span>,
                    <CurrencyDisplay
                      key="t"
                      cents={obligation.total_obligation_cents}
                    />,
                    <CurrencyDisplay key="p" cents={obligation.repaid_cents} />,
                    <CurrencyDisplay
                      key="o"
                      cents={obligation.outstanding_cents}
                    />,
                    <span key="r" className="tabular-nums">
                      {obligation.deduction_rate_percent}%
                    </span>,
                    <StatusBadge key="s" status={obligation.status} />,
                    // Suspend/resume is the only lifecycle action offered here.
                    // Completion is payroll's to declare (it fires when the
                    // balance reaches zero) and cancellation is a write-off, so
                    // neither belongs behind a one-click table button.
                    obligation.status === "active" ||
                    obligation.status === "suspended" ? (
                      <Button
                        key="a"
                        variant="outline"
                        size="sm"
                        disabled={updateObligation.isPending}
                        onClick={() => toggleSuspended(obligation)}
                      >
                        {obligation.status === "active"
                          ? t("payroll_page.cost_sharing_page.suspend")
                          : t("payroll_page.cost_sharing_page.resume")}
                      </Button>
                    ) : (
                      <span key="a" />
                    ),
                  ],
                }))}
              />
            </CardContent>
          </Card>
        )}

        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>
                {t("payroll_page.cost_sharing_page.new_obligation")}
              </DialogTitle>
            </DialogHeader>
            <form
              onSubmit={(e) => {
                e.preventDefault();
                createObligation.mutate(
                  {
                    employee_public_id: form.employee_public_id,
                    total_obligation_cents: Math.round(
                      Number(form.total_obligation_etb) * 100,
                    ),
                    deduction_rate_percent: Number(form.deduction_rate_percent),
                    started_on: form.started_on,
                    notes: form.notes || null,
                  },
                  {
                    onSuccess: () => {
                      toast.success(
                        t("payroll_page.cost_sharing_page.created"),
                      );
                      setDialogOpen(false);
                      setForm(EMPTY_FORM);
                    },
                    onError: () =>
                      toast.error(
                        t("payroll_page.cost_sharing_page.create_failed"),
                      ),
                  },
                );
              }}
              className="space-y-4"
            >
              <div>
                <Label htmlFor="cs-employee">
                  {t("payroll_page.loans_page.employee_public_id")}
                </Label>
                <Input
                  id="cs-employee"
                  value={form.employee_public_id}
                  onChange={(e) =>
                    setForm((p) => ({
                      ...p,
                      employee_public_id: e.target.value,
                    }))
                  }
                  required
                  placeholder={t("payroll_page.loans_page.paste_public_id")}
                  className="mt-1"
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label htmlFor="cs-total">
                    {t("payroll_page.cost_sharing_page.total_obligation_etb")}
                  </Label>
                  <Input
                    id="cs-total"
                    type="number"
                    min="1"
                    step="0.01"
                    value={form.total_obligation_etb}
                    onChange={(e) =>
                      setForm((p) => ({
                        ...p,
                        total_obligation_etb: e.target.value,
                      }))
                    }
                    required
                    className="mt-1"
                  />
                </div>
                <div>
                  {/*
                    No default value, matching the API. The rate comes from the
                    graduate's own agreement, and pre-filling the commonly-cited
                    10% would withhold a number nobody actually chose.
                  */}
                  <Label htmlFor="cs-rate">
                    {t("payroll_page.cost_sharing_page.rate_percent")}
                  </Label>
                  <Input
                    id="cs-rate"
                    type="number"
                    min="0.01"
                    max="100"
                    step="0.01"
                    value={form.deduction_rate_percent}
                    onChange={(e) =>
                      setForm((p) => ({
                        ...p,
                        deduction_rate_percent: e.target.value,
                      }))
                    }
                    required
                    className="mt-1"
                  />
                </div>
              </div>
              <div>
                <Label htmlFor="cs-started">
                  {t("payroll_page.cost_sharing_page.started_on")}
                </Label>
                <Input
                  id="cs-started"
                  type="date"
                  value={form.started_on}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, started_on: e.target.value }))
                  }
                  required
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="cs-notes">
                  {t("payroll_page.cost_sharing_page.notes")}
                </Label>
                <Input
                  id="cs-notes"
                  value={form.notes}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, notes: e.target.value }))
                  }
                  placeholder={t("leave_page.optional")}
                  className="mt-1"
                />
              </div>
              <DialogFooter>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setDialogOpen(false)}
                >
                  {t("common.cancel")}
                </Button>
                <Button type="submit" disabled={createObligation.isPending}>
                  {createObligation.isPending && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  {t("payroll_page.cost_sharing_page.create")}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
