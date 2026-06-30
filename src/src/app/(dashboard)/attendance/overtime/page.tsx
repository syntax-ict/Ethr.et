'use client';

import { useState } from 'react';
import { TrendingUp, Clock, Users } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { PageHeader } from '@/components/shared/page-header';
import { EmptyState } from '@/components/shared/empty-state';
import { RoleGate } from '@/components/shared/role-gate';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/api/client';

interface OvertimeResponse {
  period: string;
  employees: Array<{
    employee_public_id: string;
    employee_name: string;
    total_overtime_minutes: number;
    days_with_overtime: number;
  }>;
}

const THRESHOLD_MINUTES = 600; // 10 hours/month flag

export default function OvertimePage() {
  const [period, setPeriod] = useState<'weekly' | 'monthly'>('monthly');

  const { data, isLoading } = useQuery<OvertimeResponse>({
    queryKey: ['attendance', 'overtime', period],
    queryFn: async () => {
      const { data } = await apiClient.get('/attendance/overtime', { params: { period } });
      return data;
    },
  });

  const employees = (data?.employees ?? []).slice().sort((a, b) => b.total_overtime_minutes - a.total_overtime_minutes);
  const totalMinutes = employees.reduce((s, e) => s + e.total_overtime_minutes, 0);
  const overThreshold = employees.filter((e) => e.total_overtime_minutes > THRESHOLD_MINUTES).length;

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title="Overtime Dashboard"
          description="Overtime hours by employee with threshold alerts"
          actions={
            <Select value={period} onValueChange={(v) => setPeriod(v as 'weekly' | 'monthly')}>
              <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="weekly">This Week</SelectItem>
                <SelectItem value="monthly">This Month</SelectItem>
              </SelectContent>
            </Select>
          }
        />

        {isLoading ? (
          <div className="grid gap-4 sm:grid-cols-3">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-32" />)}</div>
        ) : (
          <>
            <div className="grid gap-4 sm:grid-cols-3">
              <Kpi icon={Users} title="Employees with OT" value={String(employees.length)} sub={`${period} period`} color="blue" />
              <Kpi icon={Clock} title="Total OT Hours" value={(totalMinutes / 60).toFixed(1)} sub="across all employees" color="purple" />
              <Kpi icon={TrendingUp} title="Over Threshold" value={String(overThreshold)} sub={`>${(THRESHOLD_MINUTES / 60).toFixed(0)}h flagged`} color={overThreshold > 0 ? 'red' : 'green'} />
            </div>

            <Card>
              <CardHeader><CardTitle className="text-base">Overtime by Employee</CardTitle></CardHeader>
              <CardContent className="p-0">
                {employees.length === 0 ? (
                  <EmptyState icon={Clock} title="No overtime recorded" description={`No employees logged overtime ${period === 'monthly' ? 'this month' : 'this week'}`} />
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead className="border-b bg-muted/50">
                        <tr>
                          <th className="px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground">Employee</th>
                          <th className="hidden px-4 py-2 text-right text-xs font-medium uppercase text-muted-foreground sm:table-cell">Days with OT</th>
                          <th className="px-4 py-2 text-right text-xs font-medium uppercase text-muted-foreground">Total Hours</th>
                          <th className="px-4 py-2 text-right text-xs font-medium uppercase text-muted-foreground">Status</th>
                        </tr>
                      </thead>
                      <tbody>
                        {employees.map((e) => {
                          const hours = e.total_overtime_minutes / 60;
                          const isFlagged = e.total_overtime_minutes > THRESHOLD_MINUTES;
                          return (
                            <tr key={e.employee_public_id} className="border-b last:border-0 hover:bg-muted/30">
                              <td className="px-4 py-3 font-medium">{e.employee_name}</td>
                              <td className="hidden px-4 py-3 text-right text-muted-foreground sm:table-cell">{e.days_with_overtime}</td>
                              <td className="px-4 py-3 text-right font-mono font-semibold">{hours.toFixed(1)}h</td>
                              <td className="px-4 py-3 text-right">
                                {isFlagged ? (
                                  <Badge variant="outline" className="bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300 border-0">Over threshold</Badge>
                                ) : (
                                  <Badge variant="outline" className="bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300 border-0">OK</Badge>
                                )}
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                )}
              </CardContent>
            </Card>
          </>
        )}
      </div>
    </RoleGate>
  );
}

const colorClass: Record<string, string> = {
  blue: 'bg-blue-100 text-blue-600 dark:bg-blue-950 dark:text-blue-400',
  purple: 'bg-purple-100 text-purple-600 dark:bg-purple-950 dark:text-purple-400',
  red: 'bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400',
  green: 'bg-green-100 text-green-600 dark:bg-green-950 dark:text-green-400',
};

function Kpi({ icon: Icon, title, value, sub, color }: { icon: React.ComponentType<{ className?: string }>; title: string; value: string; sub: string; color: string }) {
  return (
    <Card>
      <CardContent className="flex items-center gap-3 p-5">
        <div className={`flex h-11 w-11 items-center justify-center rounded-xl ${colorClass[color]}`}>
          <Icon className="h-5 w-5" />
        </div>
        <div>
          <p className="text-sm text-muted-foreground">{title}</p>
          <p className="text-2xl font-bold text-foreground">{value}</p>
          <p className="text-xs text-muted-foreground">{sub}</p>
        </div>
      </CardContent>
    </Card>
  );
}
