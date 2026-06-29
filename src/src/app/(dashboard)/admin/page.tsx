'use client';

import { Shield, Building2, TrendingUp, Activity, Users } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/shared/page-header';
import { RoleGate } from '@/components/shared/role-gate';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/api/client';

export default function AdminConsolePage() {
  const { data: revenue, isLoading: revLoading } = useQuery({
    queryKey: ['admin', 'revenue'],
    queryFn: async () => {
      const { data } = await apiClient.get('/admin/revenue');
      return data;
    },
  });

  const { data: health, isLoading: healthLoading } = useQuery({
    queryKey: ['admin', 'health'],
    queryFn: async () => {
      const { data } = await apiClient.get('/admin/health');
      return data;
    },
  });

  return (
    <RoleGate allowedRoles={['super_admin']}>
      <div className="space-y-6">
        <PageHeader
          title="Admin Console"
          description="Platform administration and monitoring"
        />

        {revLoading ? (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-28" />)}
          </div>
        ) : revenue ? (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <MetricCard icon={Building2} title="Total Tenants" value={String(revenue.total_tenants)} color="blue" />
            <MetricCard icon={Users} title="Active" value={String(revenue.active_tenants)} color="green" />
            <MetricCard icon={TrendingUp} title="Trial" value={String(revenue.trial_tenants)} color="amber" />
            <MetricCard icon={Activity} title="Conversion" value={`${revenue.conversion_rate}%`} color="purple" />
          </div>
        ) : null}

        <div className="grid gap-6 lg:grid-cols-2">
          <Card>
            <CardHeader><CardTitle className="text-base">System Health</CardTitle></CardHeader>
            <CardContent>
              {healthLoading ? (
                <Skeleton className="h-20 w-full" />
              ) : health ? (
                <div className="space-y-3">
                  {Object.entries(health.services as Record<string, { status: string }>).map(([name, svc]) => (
                    <div key={name} className="flex items-center justify-between">
                      <span className="text-sm capitalize text-foreground">{name}</span>
                      <div className="flex items-center gap-2">
                        <div className={`h-2 w-2 rounded-full ${svc.status === 'healthy' ? 'bg-green-500' : 'bg-red-500'}`} />
                        <span className="text-sm text-muted-foreground">{svc.status}</span>
                      </div>
                    </div>
                  ))}
                  <div className="flex items-center justify-between border-t pt-3">
                    <span className="text-sm text-foreground">Failed Jobs</span>
                    <span className="text-sm font-medium text-foreground">{health.failed_jobs}</span>
                  </div>
                </div>
              ) : null}
            </CardContent>
          </Card>

          {revenue?.monthly_trend && (
            <Card>
              <CardHeader><CardTitle className="text-base">Monthly Revenue Trend</CardTitle></CardHeader>
              <CardContent>
                <div className="space-y-2">
                  {(revenue.monthly_trend as Array<{ month: string; revenue_cents: number }>).slice(-6).map((m) => (
                    <div key={m.month} className="flex items-center justify-between">
                      <span className="text-sm text-muted-foreground">{m.month}</span>
                      <span className="text-sm font-medium text-foreground">
                        {(m.revenue_cents / 100).toLocaleString('en-ET', { minimumFractionDigits: 2 })} ETB
                      </span>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </RoleGate>
  );
}

const colorMap: Record<string, { bg: string; text: string }> = {
  blue: { bg: 'bg-blue-100 dark:bg-blue-950', text: 'text-blue-600 dark:text-blue-400' },
  green: { bg: 'bg-green-100 dark:bg-green-950', text: 'text-green-600 dark:text-green-400' },
  amber: { bg: 'bg-amber-100 dark:bg-amber-950', text: 'text-amber-600 dark:text-amber-400' },
  purple: { bg: 'bg-purple-100 dark:bg-purple-950', text: 'text-purple-600 dark:text-purple-400' },
};

function MetricCard({ icon: Icon, title, value, color }: {
  icon: React.ComponentType<{ className?: string }>; title: string; value: string; color: string;
}) {
  const c = colorMap[color] ?? colorMap.blue;
  return (
    <Card>
      <CardContent className="flex items-center gap-4 p-5">
        <div className={`flex h-11 w-11 items-center justify-center rounded-xl ${c.bg}`}>
          <Icon className={`h-5 w-5 ${c.text}`} />
        </div>
        <div>
          <p className="text-sm text-muted-foreground">{title}</p>
          <p className="text-2xl font-bold text-foreground">{value}</p>
        </div>
      </CardContent>
    </Card>
  );
}
