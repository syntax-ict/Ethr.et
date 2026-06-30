'use client';

import { Clock, Users } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { EmptyState } from '@/components/shared/empty-state';
import { RoleGate } from '@/components/shared/role-gate';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/api/client';

interface TeamRecord {
  public_id: string;
  employee?: { name: string; public_id: string };
  employee_name?: string;
  date: string;
  check_in: string | null;
  check_out: string | null;
  status: string;
  source: string;
}

export default function TeamAttendancePage() {
  const { data, isLoading } = useQuery({
    queryKey: ['attendance', 'team'],
    queryFn: async () => {
      const { data } = await apiClient.get('/attendance/team', { params: { per_page: 50 } });
      return data;
    },
  });

  const records: TeamRecord[] = data?.data ?? [];

  return (
    <RoleGate minRole="supervisor">
      <div className="space-y-6">
        <PageHeader title="Team Attendance" description="Today's attendance for your direct reports" />

        {isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-14 w-full" />)}
          </div>
        ) : records.length === 0 ? (
          <EmptyState
            icon={Users}
            title="No team attendance records"
            description="Your direct reports have not checked in yet today"
          />
        ) : (
          <Card>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b bg-muted/50">
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">Employee</th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">Date</th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">In</th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">Out</th>
                      <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground sm:table-cell">Source</th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {records.map((r) => (
                      <tr key={r.public_id} className="border-b last:border-0 hover:bg-muted/30">
                        <td className="px-4 py-3 text-sm font-medium text-foreground">
                          {r.employee?.name ?? r.employee_name ?? '—'}
                        </td>
                        <td className="px-4 py-3 text-sm text-muted-foreground">{r.date}</td>
                        <td className="px-4 py-3 text-sm text-muted-foreground">{r.check_in ?? '—'}</td>
                        <td className="px-4 py-3 text-sm text-muted-foreground">{r.check_out ?? '—'}</td>
                        <td className="hidden px-4 py-3 text-sm capitalize text-muted-foreground sm:table-cell">{r.source}</td>
                        <td className="px-4 py-3"><StatusBadge status={r.status} /></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        )}
      </div>
    </RoleGate>
  );
}
