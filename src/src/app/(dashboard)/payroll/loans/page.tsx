"use client";

import { useState } from "react";
import { Banknote, Plus, Loader2 } from "lucide-react";
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
import { RoleGate } from "@/components/shared/role-gate";
import { useLoans, useCreateLoan, type Loan } from "@/features/payroll/api";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

export default function LoansPage() {
  const { t } = useT();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [form, setForm] = useState({
    employee_public_id: "",
    amount_cents: "",
    monthly_deduction_cents: "",
    reason: "",
  });

  const { data, isLoading } = useLoans();
  const createLoan = useCreateLoan();

  const loans: Loan[] = data?.data ?? [];

  return (
    <RoleGate allowedRoles={["finance_admin", "tenant_admin", "super_admin"]}>
      <div className="space-y-6">
        <PageHeader
          title={t("payroll_page.loans_page.title")}
          description={t("payroll_page.loans_page.description")}
          actions={
            <Button onClick={() => setDialogOpen(true)}>
              <Plus className="mr-2 h-4 w-4" />{" "}
              {t("payroll_page.loans_page.new_loan")}
            </Button>
          }
        />

        {isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-20" />
            ))}
          </div>
        ) : loans.length === 0 ? (
          <EmptyState
            icon={Banknote}
            title={t("payroll_page.loans_page.no_loans")}
            description={t("payroll_page.loans_page.no_loans_desc")}
          />
        ) : (
          <Card>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b bg-muted/50">
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                        {t("attendance.employee")}
                      </th>
                      <th className="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted-foreground">
                        {t("payroll_page.loans_page.amount")}
                      </th>
                      <th className="hidden px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted-foreground sm:table-cell">
                        {t("payroll_page.loans_page.remaining")}
                      </th>
                      <th className="hidden px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted-foreground md:table-cell">
                        {t("payroll_page.loans_page.monthly")}
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                        {t("common.status")}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {loans.map((loan) => (
                      <tr
                        key={loan.public_id}
                        className="border-b last:border-0 hover:bg-muted/30"
                      >
                        <td className="px-4 py-3 text-sm font-medium text-foreground">
                          {loan.employee?.name ?? "—"}
                        </td>
                        <td className="px-4 py-3 text-right text-sm">
                          <CurrencyDisplay cents={loan.amount_cents} />
                        </td>
                        <td className="hidden px-4 py-3 text-right text-sm sm:table-cell">
                          <CurrencyDisplay cents={loan.remaining_cents} />
                        </td>
                        <td className="hidden px-4 py-3 text-right text-sm md:table-cell">
                          <CurrencyDisplay
                            cents={loan.monthly_deduction_cents}
                          />
                        </td>
                        <td className="px-4 py-3">
                          <StatusBadge status={loan.status} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        )}

        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{t("payroll_page.loans_page.new_employee_loan")}</DialogTitle>
            </DialogHeader>
            <form
              onSubmit={(e) => {
                e.preventDefault();
                createLoan.mutate(
                  {
                    employee_public_id: form.employee_public_id,
                    amount_cents: parseInt(form.amount_cents) * 100,
                    monthly_deduction_cents:
                      parseInt(form.monthly_deduction_cents) * 100,
                  },
                  {
                    onSuccess: () => {
                      toast.success(t("payroll_page.loans_page.created"));
                      setDialogOpen(false);
                      setForm({
                        employee_public_id: "",
                        amount_cents: "",
                        monthly_deduction_cents: "",
                        reason: "",
                      });
                    },
                    onError: () =>
                      toast.error(t("payroll_page.loans_page.create_failed")),
                  },
                );
              }}
              className="space-y-4"
            >
              <div>
                <Label>{t("payroll_page.loans_page.employee_public_id")}</Label>
                <Input
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
                  <Label>{t("payroll_page.loans_page.loan_amount")}</Label>
                  <Input
                    type="number"
                    value={form.amount_cents}
                    onChange={(e) =>
                      setForm((p) => ({ ...p, amount_cents: e.target.value }))
                    }
                    required
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label>{t("payroll_page.loans_page.monthly_deduction")}</Label>
                  <Input
                    type="number"
                    value={form.monthly_deduction_cents}
                    onChange={(e) =>
                      setForm((p) => ({
                        ...p,
                        monthly_deduction_cents: e.target.value,
                      }))
                    }
                    required
                    className="mt-1"
                  />
                </div>
              </div>
              <div>
                <Label>{t("attendance.corrections.reason")}</Label>
                <Input
                  value={form.reason}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, reason: e.target.value }))
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
                <Button type="submit" disabled={createLoan.isPending}>
                  {createLoan.isPending && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  {t("payroll_page.loans_page.create_loan")}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
