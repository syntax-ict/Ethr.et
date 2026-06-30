'use client';

import Link from 'next/link';
import {
  Fingerprint, Wifi, WifiOff, AlertTriangle, Clock, Activity, ArrowLeft,
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/shared/page-header';
import { RoleGate } from '@/components/shared/role-gate';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/api/client';

interface DashboardResponse {
  total: number;
  online: number;
  offline: number;
  error: number;
  pending: number;
  events_today: number;
}

interface Device {
  public_id: string;
  name: string;
  brand: string;
  status: string;
  last_seen_at: string | null;
  ip_address?: string;
  branch?: { name: string };
}

export default function DeviceDashboardPage() {
  const { data: stats, isLoading: statsLoading } = useQuery<DashboardResponse>({
    queryKey: ['devices', 'dashboard'],
    queryFn: async () => (await apiClient.get('/devices/dashboard')).data,
    refetchInterval: 30000, // refresh every 30s
  });

  const { data: devices, isLoading: devicesLoading } = useQuery({
    queryKey: ['devices'],
    queryFn: async () => (await apiClient.get('/devices')).data,
    refetchInterval: 30000,
  });

  const allDevices: Device[] = devices?.data ?? [];

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="sm" asChild>
            <Link href="/devices"><ArrowLeft className="mr-2 h-4 w-4" /> All devices</Link>
          </Button>
        </div>

        <PageHeader
          title="Device Health Dashboard"
          description="Live status of all biometric devices · auto-refresh every 30s"
        />

        {statsLoading || !stats ? (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">{Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-24" />)}</div>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <StatusCard icon={Fingerprint} title="Total Devices" value={stats.total} color="blue" />
            <StatusCard icon={Wifi} title="Online" value={stats.online} color="green" />
            <StatusCard icon={WifiOff} title="Offline" value={stats.offline} color="gray" />
            <StatusCard icon={AlertTriangle} title="Error" value={stats.error} color="red" />
            <StatusCard icon={Activity} title="Events Today" value={stats.events_today} color="purple" />
          </div>
        )}

        <Card>
          <CardHeader><CardTitle className="text-base">Device Status</CardTitle></CardHeader>
          <CardContent className="p-0">
            {devicesLoading ? (
              <div className="space-y-2 p-4">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-14" />)}</div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b bg-muted/50">
                      <th className="px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground">Device</th>
                      <th className="hidden px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground sm:table-cell">Brand</th>
                      <th className="hidden px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground md:table-cell">IP</th>
                      <th className="hidden px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground md:table-cell">Branch</th>
                      <th className="px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground">Status</th>
                      <th className="hidden px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground lg:table-cell">Last Seen</th>
                    </tr>
                  </thead>
                  <tbody>
                    {allDevices.length === 0 ? (
                      <tr><td colSpan={6} className="px-4 py-12 text-center text-sm text-muted-foreground">No devices registered yet</td></tr>
                    ) : (
                      allDevices.map((d) => (
                        <tr key={d.public_id} className="border-b last:border-0 hover:bg-muted/30">
                          <td className="px-4 py-3 text-sm font-medium">{d.name}</td>
                          <td className="hidden px-4 py-3 text-sm capitalize text-muted-foreground sm:table-cell">{d.brand}</td>
                          <td className="hidden px-4 py-3 text-sm font-mono text-muted-foreground md:table-cell">{d.ip_address ?? '—'}</td>
                          <td className="hidden px-4 py-3 text-sm text-muted-foreground md:table-cell">{d.branch?.name ?? '—'}</td>
                          <td className="px-4 py-3">
                            <Badge variant="outline" className={statusClass(d.status)}>
                              <span className={`mr-1.5 inline-block h-1.5 w-1.5 rounded-full ${dotClass(d.status)}`} />
                              {d.status}
                            </Badge>
                          </td>
                          <td className="hidden px-4 py-3 text-xs text-muted-foreground lg:table-cell">
                            {d.last_seen_at ? (
                              <span className="flex items-center gap-1">
                                <Clock className="h-3 w-3" /> {timeAgo(d.last_seen_at)}
                              </span>
                            ) : 'Never'}
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </RoleGate>
  );
}

const colorClass: Record<string, string> = {
  blue: 'bg-blue-100 text-blue-600 dark:bg-blue-950 dark:text-blue-400',
  green: 'bg-green-100 text-green-600 dark:bg-green-950 dark:text-green-400',
  gray: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
  red: 'bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400',
  purple: 'bg-purple-100 text-purple-600 dark:bg-purple-950 dark:text-purple-400',
};

function StatusCard({ icon: Icon, title, value, color }: { icon: React.ComponentType<{ className?: string }>; title: string; value: number; color: string }) {
  return (
    <Card>
      <CardContent className="flex items-center gap-3 p-4">
        <div className={`flex h-10 w-10 items-center justify-center rounded-xl ${colorClass[color]}`}>
          <Icon className="h-5 w-5" />
        </div>
        <div>
          <p className="text-xs text-muted-foreground">{title}</p>
          <p className="text-2xl font-bold text-foreground">{value}</p>
        </div>
      </CardContent>
    </Card>
  );
}

function statusClass(status: string): string {
  const map: Record<string, string> = {
    online: 'border-0 bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    offline: 'border-0 bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
    error: 'border-0 bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
    pending: 'border-0 bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300',
  };
  return map[status] ?? '';
}

function dotClass(status: string): string {
  const map: Record<string, string> = {
    online: 'bg-green-500 animate-pulse',
    offline: 'bg-gray-400',
    error: 'bg-red-500',
    pending: 'bg-amber-500',
  };
  return map[status] ?? 'bg-gray-400';
}

function timeAgo(iso: string): string {
  const diffMs = Date.now() - new Date(iso).getTime();
  const m = Math.floor(diffMs / 60000);
  if (m < 1) return 'just now';
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}h ago`;
  return `${Math.floor(h / 24)}d ago`;
}
