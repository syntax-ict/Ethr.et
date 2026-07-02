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
import { toast } from "sonner";

export default function LoansPage() {
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
          title="Employee Loans"
          description="Manage employee loans and deductions"
          actions={
            <Button onClick={() => setDialogOpen(true)}>
              <Plus className="mr-2 h-4 w-4" /> New Loan
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
            title="No loans"
            description="No employee loans recorded"
          />
        ) : (
          <Card>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b bg-muted/50">
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                        Employee
                      </th>
                      <th className="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted-foreground">
                        Amount
                      </th>
                      <th className="hidden px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted-foreground sm:table-cell">
                        Remaining
                      </th>
                      <th className="hidden px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted-foreground md:table-cell">
                        Monthly
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                        Status
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
              <DialogTitle>New Employee Loan</DialogTitle>
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
                      toast.success("Loan created");
                      setDialogOpen(false);
                      setForm({
                        employee_public_id: "",
                        amount_cents: "",
                        monthly_deduction_cents: "",
                        reason: "",
                      });
                    },
                    onError: () => toast.error("Failed to create loan"),
                  },
                );
              }}
              className="space-y-4"
            >
              <div>
                <Label>Employee Public ID</Label>
                <Input
                  value={form.employee_public_id}
                  onChange={(e) =>
                    setForm((p) => ({
                      ...p,
                      employee_public_id: e.target.value,
                    }))
                  }
                  required
                  placeholder="Paste employee public_id"
                  className="mt-1"
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>Loan Amount (ETB)</Label>
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
                  <Label>Monthly Deduction (ETB)</Label>
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
                <Label>Reason</Label>
                <Input
                  value={form.reason}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, reason: e.target.value }))
                  }
                  placeholder="Optional"
                  className="mt-1"
                />
              </div>
              <DialogFooter>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setDialogOpen(false)}
                >
                  Cancel
                </Button>
                <Button type="submit" disabled={createLoan.isPending}>
                  {createLoan.isPending && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  Create Loan
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
