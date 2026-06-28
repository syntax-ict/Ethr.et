'use client';

import { use } from 'react';
import Link from 'next/link';
import { ArrowLeft, Download } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusBadge } from '@/components/shared/status-badge';
import { CurrencyDisplay } from '@/components/shared/currency-display';
import { usePayrollRun } from '@/features/payroll/api';

export default function PayrollDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = use(params);
  const { data: run, isLoading } = usePayrollRun(id);

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-48" />
        <div className="grid gap-4 sm:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-24" />
          ))}
        </div>
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (!run) {
    return (
      <div className="py-16 text-center">
        <p className="text-muted-foreground">Payroll run not found</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="sm" asChild>
          <Link href="/payroll">
            <ArrowLeft className="mr-2 h-4 w-4" />
            Back
          </Link>
        </Button>
      </div>

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-2xl font-bold text-foreground">{run.period_label}</h1>
            <StatusBadge status={run.status} />
          </div>
          <p className="mt-1 text-sm text-muted-foreground">
            {run.period_start} to {run.period_end} &middot; {run.employee_count} employees
          </p>
        </div>
        <Button variant="outline" size="sm">
          <Download className="mr-2 h-4 w-4" />
          Export
        </Button>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <SummaryCard label="Gross Total" cents={run.gross_total_cents} />
        <SummaryCard label="Net Total" cents={run.net_total_cents} highlight />
        <SummaryCard label="Total Tax" cents={run.tax_total_cents} />
        <Card>
          <CardContent className="p-4">
            <p className="text-sm text-muted-foreground">Employees</p>
            <p className="mt-1 text-2xl font-bold text-foreground">{run.employee_count}</p>
          </CardContent>
        </Card>
      </div>

      {run.entries && run.entries.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Payroll Entries</CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b bg-muted/50">
                    <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">Employee</th>
                    <th className="px-4 py-3 text-right text-sm font-medium text-muted-foreground">Basic</th>
                    <th className="hidden px-4 py-3 text-right text-sm font-medium text-muted-foreground md:table-cell">Gross</th>
                    <th className="hidden px-4 py-3 text-right text-sm font-medium text-muted-foreground sm:table-cell">Tax</th>
                    <th className="hidden px-4 py-3 text-right text-sm font-medium text-muted-foreground sm:table-cell">Pension</th>
                    <th className="px-4 py-3 text-right text-sm font-medium text-muted-foreground">Net</th>
                  </tr>
                </thead>
                <tbody>
                  {run.entries.map((entry: {
                    public_id: string;
                    employee?: { name: string };
                    basic_salary_cents: number;
                    gross_cents: number;
                    income_tax_cents: number;
                    employee_pension_cents: number;
                    net_cents: number;
                  }) => (
                    <tr key={entry.public_id} className="border-b last:border-0 hover:bg-muted/30">
                      <td className="px-4 py-3 text-sm font-medium text-foreground">
                        {entry.employee?.name ?? '—'}
                      </td>
                      <td className="px-4 py-3 text-right text-sm text-muted-foreground">
                        <CurrencyDisplay cents={entry.basic_salary_cents} />
                      </td>
                      <td className="hidden px-4 py-3 text-right text-sm text-muted-foreground md:table-cell">
                        <CurrencyDisplay cents={entry.gross_cents} />
                      </td>
                      <td className="hidden px-4 py-3 text-right text-sm text-muted-foreground sm:table-cell">
                        <CurrencyDisplay cents={entry.income_tax_cents} />
                      </td>
                      <td className="hidden px-4 py-3 text-right text-sm text-muted-foreground sm:table-cell">
                        <CurrencyDisplay cents={entry.employee_pension_cents} />
                      </td>
                      <td className="px-4 py-3 text-right text-sm font-semibold text-foreground">
                        <CurrencyDisplay cents={entry.net_cents} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}

function SummaryCard({ label, cents, highlight }: { label: string; cents: number; highlight?: boolean }) {
  return (
    <Card>
      <CardContent className="p-4">
        <p className="text-sm text-muted-foreground">{label}</p>
        <p className={`mt-1 text-2xl font-bold ${highlight ? 'text-primary' : 'text-foreground'}`}>
          <CurrencyDisplay cents={cents} />
        </p>
      </CardContent>
    </Card>
  );
}
