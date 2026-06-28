'use client';

import { useState } from 'react';
import Link from 'next/link';
import { Wallet } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { CurrencyDisplay } from '@/components/shared/currency-display';
import { EmptyState } from '@/components/shared/empty-state';
import { usePayrollRuns } from '@/features/payroll/api';

export default function PayrollPage() {
  const [page, setPage] = useState(1);
  const { data, isLoading } = usePayrollRuns({ page });

  return (
    <div className="space-y-6">
      <PageHeader
        title="Payroll"
        description="View payroll runs and processing history"
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
    </div>
  );
}
