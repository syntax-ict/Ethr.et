'use client';

import { useState } from 'react';
import { BarChart3, Download, FileText, Users, Clock, CalendarDays, Wallet } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHeader } from '@/components/shared/page-header';
import { apiClient } from '@/api/client';
import { RoleGate } from '@/components/shared/role-gate';
import { toast } from 'sonner';

const prebuiltReports = [
  { key: 'employees', icon: Users, title: 'Employee Directory', description: 'Full employee listing with department and status' },
  { key: 'attendance', icon: Clock, title: 'Attendance Summary', description: 'Monthly attendance records for all employees' },
  { key: 'leave', icon: CalendarDays, title: 'Leave Balance Report', description: 'Leave balances for all employees this year' },
  { key: 'payroll', icon: Wallet, title: 'Payroll Register', description: 'Detailed payroll entries by period' },
];

export default function ReportsPage() {
  const [generating, setGenerating] = useState<string | null>(null);

  async function handleGenerate(source: string) {
    setGenerating(source);
    try {
      const { data } = await apiClient.post('/reports/generate', {
        source,
        columns: [],
      });
      toast.success(`Report generated: ${data.total} records`);
    } catch {
      toast.error('Failed to generate report');
    } finally {
      setGenerating(null);
    }
  }

  return (
    <RoleGate minRole="hr_admin">
    <div className="space-y-6">
      <PageHeader
        title="Reports"
        description="Generate and export reports from your data"
      />

      <div className="grid gap-4 sm:grid-cols-2">
        {prebuiltReports.map((report) => {
          const Icon = report.icon;
          return (
            <Card key={report.key}>
              <CardHeader className="pb-3">
                <div className="flex items-center gap-3">
                  <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10">
                    <Icon className="h-4 w-4 text-primary" />
                  </div>
                  <CardTitle className="text-base">{report.title}</CardTitle>
                </div>
              </CardHeader>
              <CardContent>
                <p className="mb-4 text-sm text-muted-foreground">{report.description}</p>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={generating === report.key}
                  onClick={() => handleGenerate(report.key)}
                >
                  <Download className="mr-2 h-3 w-3" />
                  {generating === report.key ? 'Generating...' : 'Generate'}
                </Button>
              </CardContent>
            </Card>
          );
        })}
      </div>
    </div>
    </RoleGate>
  );
}
