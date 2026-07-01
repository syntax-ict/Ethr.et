'use client';

import Link from 'next/link';
import {
  Fingerprint, Wifi, WifiOff, AlertTriangle, Clock, Activity, ArrowLeft, Plus,
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
  auto_sync_enabled: number;
  events_today: number;
  last_sync_at: string | null;
  sync_stats_24h: {
    success: number;
    partial: number;
    failed: number;
    offline: number;
  };
}

interface Device {
  public_id: string;
  name: string;
  adapter_type: string;
  serial_number: string | null;
  status: string;
  last_sync_at: string | null;
  branch?: { name: string } | null;
  attendance_records_count?: number;
}

export default function DeviceDashboardPage() {
  const { data: stats, isLoading: statsLoading } = useQuery<DashboardResponse>({
    queryKey: ['devices', 'dashboard'],
    queryFn: async () => (await apiClient.get('/devices/dashboard')).data,
    refetchInterval: 30000,
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
        <div className="flex items-center justify-between">
          <Button variant="ghost" size="sm" asChild>
            <Link href="/devices"><ArrowLeft className="mr-2 h-4 w-4" /> All Devices</Link>
          </Button>
          <Button size="sm" asChild>
            <Link href="/devices"><Plus className="mr-2 h-4 w-4" /> Add Device</Link>
          </Button>
        </div>

        <PageHeader
          title="Device Health Dashboard"
          description="Live status of all biometric devices — auto-refresh every 30s"
        />

        {statsLoading || !stats ? (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-24" />)}
          </div>
        ) : (
          <>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
              <StatusCard icon={Fingerprint} title="Total Devices" value={stats.total} color="blue" />
              <StatusCard icon={Wifi} title="Online" value={stats.online} color="green" />
              <StatusCard icon={WifiOff} title="Offline" value={stats.offline} color="gray" />
              <StatusCard icon={AlertTriangle} title="Error" value={stats.error} color="red" />
              <StatusCard icon={Activity} title="Events Today" value={stats.events_today} color="purple" />
            </div>

            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="text-sm">Sync Overview (24h)</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                  <div>
                    <p className="text-xs text-muted-foreground">Successful</p>
                    <p className="text-xl font-bold text-green-600">{stats.sync_stats_24h.success}</p>
                  </div>
                  <div>
                    <p className="text-xs text-muted-foreground">Partial</p>
                    <p className="text-xl font-bold text-amber-600">{stats.sync_stats_24h.partial}</p>
                  </div>
                  <div>
                    <p className="text-xs text-muted-foreground">Failed</p>
                    <p className="text-xl font-bold text-red-600">{stats.sync_stats_24h.failed}</p>
                  </div>
                  <div>
                    <p className="text-xs text-muted-foreground">Auto-Sync Enabled</p>
                    <p className="text-xl font-bold text-foreground">{stats.auto_sync_enabled}</p>
                  </div>
                </div>
                {stats.last_sync_at && (
                  <p className="mt-3 text-xs text-muted-foreground">
                    Last sync across all devices: {timeAgo(stats.last_sync_at)}
                  </p>
                )}
              </CardContent>
            </Card>
          </>
        )}

        <Card>
          <CardHeader><CardTitle className="text-base">Device Status</CardTitle></CardHeader>
          <CardContent className="p-0">
            {devicesLoading ? (
              <div className="space-y-2 p-4">
                {Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-14" />)}
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b bg-muted/50">
                      <th className="px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground">Device</th>
                      <th className="hidden px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground sm:table-cell">Adapter</th>
                      <th className="hidden px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground md:table-cell">Serial</th>
                      <th className="hidden px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground md:table-cell">Branch</th>
                      <th className="px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground">Status</th>
                      <th className="hidden px-4 py-2 text-right text-xs font-medium uppercase text-muted-foreground lg:table-cell">Records</th>
                      <th className="hidden px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground lg:table-cell">Last Sync</th>
                    </tr>
                  </thead>
                  <tbody>
                    {allDevices.length === 0 ? (
                      <tr>
                        <td colSpan={7} className="px-4 py-12 text-center text-sm text-muted-foreground">
                          No devices registered yet
                        </td>
                      </tr>
                    ) : (
                      allDevices.map((d) => (
                        <tr key={d.public_id} className="border-b last:border-0 hover:bg-muted/30">
                          <td className="px-4 py-3 text-sm font-medium">{d.name}</td>
                          <td className="hidden px-4 py-3 text-sm capitalize text-muted-foreground sm:table-cell">
                            {ADAPTER_LABELS[d.adapter_type] ?? d.adapter_type}
                          </td>
                          <td className="hidden px-4 py-3 text-sm font-mono text-muted-foreground md:table-cell">
                            {d.serial_number ?? '—'}
                          </td>
                          <td className="hidden px-4 py-3 text-sm text-muted-foreground md:table-cell">
                            {d.branch?.name ?? '—'}
                          </td>
                          <td className="px-4 py-3">
                            <Badge variant="outline" className={statusClass(d.status)}>
                              <span className={`mr-1.5 inline-block h-1.5 w-1.5 rounded-full ${dotClass(d.status)}`} />
                              {d.status}
                            </Badge>
                          </td>
                          <td className="hidden px-4 py-3 text-right text-sm tabular-nums text-muted-foreground lg:table-cell">
                            {d.attendance_records_count?.toLocaleString() ?? '—'}
                          </td>
                          <td className="hidden px-4 py-3 text-xs text-muted-foreground lg:table-cell">
                            {d.last_sync_at ? (
                              <span className="flex items-center gap-1">
                                <Clock className="h-3 w-3" /> {timeAgo(d.last_sync_at)}
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

const ADAPTER_LABELS: Record<string, string> = {
  hikvision: 'Hikvision',
  zkteco: 'ZKTeco',
  suprema: 'Suprema',
  mock: 'Mock',
};

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
