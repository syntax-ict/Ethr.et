'use client';

import { useState } from 'react';
import { BarChart3, Download, Loader2, Users, Clock, CalendarDays, Wallet, FileSpreadsheet } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { PageHeader } from '@/components/shared/page-header';
import { EmptyState } from '@/components/shared/empty-state';
import { RoleGate } from '@/components/shared/role-gate';
import { useMutation } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import { toast } from 'sonner';

interface ReportResult {
  source: string;
  total: number;
  data: Array<Record<string, unknown>>;
  summary?: { grouped_by?: string; groups?: Record<string, number> };
}

const prebuilt = [
  { key: 'employees', icon: Users, title: 'Employee Directory', description: 'Full employee listing', color: 'text-blue-600' },
  { key: 'attendance', icon: Clock, title: 'Attendance Summary', description: 'Monthly attendance records', color: 'text-green-600' },
  { key: 'leave', icon: CalendarDays, title: 'Leave Balance Report', description: 'Leave balances for all employees', color: 'text-purple-600' },
  { key: 'payroll', icon: Wallet, title: 'Payroll Register', description: 'Detailed payroll entries', color: 'text-amber-600' },
];

export default function ReportsPage() {
  const [activeReport, setActiveReport] = useState<string | null>(null);
  const [result, setResult] = useState<ReportResult | null>(null);

  const generate = useMutation({
    mutationFn: async (source: string) => {
      const { data } = await apiClient.post<ReportResult>('/reports/generate', { source });
      return data;
    },
    onSuccess: (data, source) => {
      setActiveReport(source);
      setResult(data);
      toast.success(`Generated ${data.total} records`);
    },
    onError: () => toast.error('Failed to generate report'),
  });

  function downloadCsv() {
    if (!result?.data?.length) return;
    const headers = Object.keys(result.data[0]);
    const csv = [
      headers.join(','),
      ...result.data.map((row) =>
        headers.map((h) => {
          const v = row[h];
          const s = v == null ? '' : String(v);
          return s.includes(',') || s.includes('"') ? `"${s.replace(/"/g, '""')}"` : s;
        }).join(',')
      ),
    ].join('\n');

    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `${activeReport}-report-${new Date().toISOString().split('T')[0]}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  }

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader title="Reports" description="Generate, preview, and export reports from your data" />

        <Tabs defaultValue="prebuilt">
          <TabsList>
            <TabsTrigger value="prebuilt">Pre-built Reports</TabsTrigger>
            <TabsTrigger value="preview" disabled={!result}>Preview</TabsTrigger>
          </TabsList>

          <TabsContent value="prebuilt" className="mt-4">
            <div className="grid gap-4 sm:grid-cols-2">
              {prebuilt.map((r) => {
                const Icon = r.icon;
                const isLoading = generate.isPending && generate.variables === r.key;
                return (
                  <Card key={r.key} className="hover:shadow-md transition-shadow">
                    <CardHeader className="pb-3">
                      <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                          <Icon className={`h-5 w-5 ${r.color}`} />
                        </div>
                        <CardTitle className="text-base">{r.title}</CardTitle>
                      </div>
                    </CardHeader>
                    <CardContent>
                      <p className="mb-4 text-sm text-muted-foreground">{r.description}</p>
                      <Button size="sm" variant="outline" onClick={() => generate.mutate(r.key)} disabled={isLoading}>
                        {isLoading ? <Loader2 className="mr-2 h-3 w-3 animate-spin" /> : <BarChart3 className="mr-2 h-3 w-3" />}
                        Generate
                      </Button>
                    </CardContent>
                  </Card>
                );
              })}
            </div>
          </TabsContent>

          <TabsContent value="preview" className="mt-4 space-y-4">
            {result && (
              <>
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-muted-foreground">
                      {result.total} records · Source: <span className="capitalize">{result.source}</span>
                    </p>
                  </div>
                  <Button size="sm" onClick={downloadCsv}>
                    <Download className="mr-2 h-4 w-4" />
                    Download CSV
                  </Button>
                </div>

                {result.data.length === 0 ? (
                  <EmptyState icon={FileSpreadsheet} title="No data" description="The report returned no records" />
                ) : (
                  <Card>
                    <CardContent className="p-0">
                      <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                          <thead>
                            <tr className="border-b bg-muted/50">
                              {Object.keys(result.data[0]).map((col) => (
                                <th key={col} className="px-4 py-2 text-left font-medium text-muted-foreground capitalize whitespace-nowrap">
                                  {col.replace(/_/g, ' ').replace(/cents/i, '(¢)')}
                                </th>
                              ))}
                            </tr>
                          </thead>
                          <tbody>
                            {result.data.slice(0, 100).map((row, i) => (
                              <tr key={i} className="border-b last:border-0 hover:bg-muted/30">
                                {Object.entries(row).map(([k, v]) => (
                                  <td key={k} className="px-4 py-2 text-foreground whitespace-nowrap">
                                    {v == null ? '—' : String(v)}
                                  </td>
                                ))}
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                      {result.data.length > 100 && (
                        <div className="border-t bg-muted/30 px-4 py-2 text-xs text-muted-foreground">
                          Showing first 100 of {result.data.length} rows. Download CSV for full data.
                        </div>
                      )}
                    </CardContent>
                  </Card>
                )}
              </>
            )}
          </TabsContent>
        </Tabs>
      </div>
    </RoleGate>
  );
}
