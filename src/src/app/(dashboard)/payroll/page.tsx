'use client';

import { useState } from 'react';
import Link from 'next/link';
import { Wallet, Plus, Loader2 } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { CurrencyDisplay } from '@/components/shared/currency-display';
import { EmptyState } from '@/components/shared/empty-state';
import { RoleGate } from '@/components/shared/role-gate';
import { usePayrollRuns } from '@/features/payroll/api';
import { usePermissions } from '@/lib/hooks/usePermissions';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import { toast } from 'sonner';

export default function PayrollPage() {
  const [page, setPage] = useState(1);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [periodStart, setPeriodStart] = useState('');
  const [periodEnd, setPeriodEnd] = useState('');
  const { data, isLoading } = usePayrollRuns({ page });
  const { can } = usePermissions();
  const queryClient = useQueryClient();

  const processPayroll = useMutation({
    mutationFn: async (payload: { period_start: string; period_end: string }) => {
      const { data } = await apiClient.post('/payroll/process', payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payroll'] });
      toast.success('Payroll processing started');
      setDialogOpen(false);
      setPeriodStart('');
      setPeriodEnd('');
    },
    onError: (err: unknown) => {
      const axiosError = err as { response?: { data?: { detail?: string } } };
      toast.error(axiosError.response?.data?.detail || 'Failed to process payroll');
    },
  });

  function handleRunPayroll(e: React.FormEvent) {
    e.preventDefault();
    processPayroll.mutate({ period_start: periodStart, period_end: periodEnd });
  }

  return (
    <RoleGate allowedRoles={['finance_admin', 'tenant_admin', 'super_admin']}>
    <div className="space-y-6">
      <PageHeader
        title="Payroll"
        description="View payroll runs and processing history"
        actions={
          can.processPayroll && (
            <Button onClick={() => setDialogOpen(true)}>
              <Plus className="mr-2 h-4 w-4" />
              Run Payroll
            </Button>
          )
        }
      />

      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-20 w-full" />
          ))}
        </div>
      ) : !data?.data?.length ? (
        <EmptyState
          icon={Wallet}
          title="No payroll runs"
          description="Payroll runs will appear here once processed"
        />
      ) : (
        <>
          <div className="grid gap-4">
            {data.data.map((run) => (
              <Card key={run.public_id}>
                <CardContent className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <div className="flex items-center gap-3">
                      <h3 className="font-semibold text-foreground">{run.period_label}</h3>
                      <StatusBadge status={run.status} />
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground">
                      {run.employee_count} employees &middot; {run.period_start} to {run.period_end}
                    </p>
                  </div>
                  <div className="flex items-center gap-6">
                    <div className="text-right">
                      <p className="text-xs text-muted-foreground">Gross</p>
                      <CurrencyDisplay cents={run.gross_total_cents} className="text-sm font-medium" />
                    </div>
                    <div className="text-right">
                      <p className="text-xs text-muted-foreground">Net</p>
                      <CurrencyDisplay cents={run.net_total_cents} className="text-sm font-semibold text-foreground" />
                    </div>
                    <Button variant="outline" size="sm" asChild>
                      <Link href={`/payroll/${run.public_id}`}>View</Link>
                    </Button>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>

          {data.meta && data.meta.last_page > 1 && (
            <div className="flex items-center justify-between">
              <p className="text-sm text-muted-foreground">
                Showing {data.meta.from}–{data.meta.to} of {data.meta.total}
              </p>
              <div className="flex gap-2">
                <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
                <Button variant="outline" size="sm" disabled={page >= data.meta.last_page} onClick={() => setPage(page + 1)}>Next</Button>
              </div>
            </div>
          )}
        </>
      )}
      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Run Payroll</DialogTitle>
          </DialogHeader>
          <form onSubmit={handleRunPayroll} className="space-y-4">
            <div>
              <Label htmlFor="period_start">Period Start</Label>
              <Input
                id="period_start"
                type="date"
                value={periodStart}
                onChange={(e) => setPeriodStart(e.target.value)}
                required
                className="mt-1"
              />
            </div>
            <div>
              <Label htmlFor="period_end">Period End</Label>
              <Input
                id="period_end"
                type="date"
                value={periodEnd}
                onChange={(e) => setPeriodEnd(e.target.value)}
                required
                className="mt-1"
              />
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={processPayroll.isPending}>
                {processPayroll.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Process Payroll
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
    </RoleGate>
  );
}
