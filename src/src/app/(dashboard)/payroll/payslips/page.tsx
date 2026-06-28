'use client';

import { useState } from 'react';
import { FileText, Download } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/shared/page-header';
import { CurrencyDisplay } from '@/components/shared/currency-display';
import { EmptyState } from '@/components/shared/empty-state';
import { useMyPayslips } from '@/features/payroll/api';

export default function MyPayslipsPage() {
  const [page, setPage] = useState(1);
  const { data, isLoading } = useMyPayslips({ page });

  return (
    <div className="space-y-6">
      <PageHeader title="My Payslips" description="View your salary breakdown by period" />

      {isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-40" />
          ))}
        </div>
      ) : !data?.data?.length ? (
        <EmptyState
          icon={FileText}
          title="No payslips yet"
          description="Your payslips will appear here after payroll is processed"
        />
      ) : (
        <div className="grid gap-4 sm:grid-cols-2">
          {data.data.map((entry) => (
            <Card key={entry.public_id}>
              <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                  <CardTitle className="text-base">Payslip</CardTitle>
                  <Button variant="ghost" size="sm">
                    <Download className="h-4 w-4" />
                  </Button>
                </div>
              </CardHeader>
              <CardContent>
                <div className="space-y-2">
                  <Row label="Basic Salary" cents={entry.basic_salary_cents} />
                  <Row label="Gross" cents={entry.gross_cents} />
                  <div className="border-t pt-2">
                    <Row label="Income Tax" cents={entry.income_tax_cents} deduction />
                    <Row label="Employee Pension" cents={entry.employee_pension_cents} deduction />
                    {entry.other_deductions_cents > 0 && (
                      <Row label="Other Deductions" cents={entry.other_deductions_cents} deduction />
                    )}
                  </div>
                  <div className="border-t pt-2">
                    <div className="flex items-center justify-between">
                      <span className="text-sm font-semibold text-foreground">Net Pay</span>
                      <CurrencyDisplay cents={entry.net_cents} className="text-sm font-bold text-primary" />
                    </div>
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}

function Row({ label, cents, deduction }: { label: string; cents: number; deduction?: boolean }) {
  return (
    <div className="flex items-center justify-between">
      <span className="text-sm text-muted-foreground">{label}</span>
      <span className={`text-sm ${deduction ? 'text-red-500' : 'text-foreground'}`}>
        {deduction && '- '}
        <CurrencyDisplay cents={cents} />
      </span>
    </div>
  );
}
