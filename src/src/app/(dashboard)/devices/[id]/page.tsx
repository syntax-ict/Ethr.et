'use client';

import { use, useState } from 'react';
import Link from 'next/link';
import {
  ArrowLeft, RefreshCw, Signal, Loader2, Pencil, Trash2,
  Copy, RotateCw, CheckCircle, XCircle, AlertTriangle,
  Clock, Wifi, WifiOff, Activity, ChevronLeft, ChevronRight,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from '@/components/ui/dialog';
import { PageHeader } from '@/components/shared/page-header';
import { RoleGate } from '@/components/shared/role-gate';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import { toast } from 'sonner';

const ADAPTER_LABELS: Record<string, string> = {
  hikvision: 'Hikvision', zkteco: 'ZKTeco', suprema: 'Suprema', mock: 'Mock (Simulator)',
};

const STATUS_STYLES: Record<string, string> = {
  online: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300 border-0',
  offline: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400 border-0',
  error: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300 border-0',
  pending: 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300 border-0',
};

const SYNC_STATUS_ICON: Record<string, React.ReactNode> = {
  success: <CheckCircle className="h-4 w-4 text-green-600" />,
  partial: <AlertTriangle className="h-4 w-4 text-amber-500" />,
  failed: <XCircle className="h-4 w-4 text-red-500" />,
  offline: <WifiOff className="h-4 w-4 text-gray-400" />,
  running: <Loader2 className="h-4 w-4 animate-spin text-blue-500" />,
};

interface Device {
  public_id: string;
  name: string;
  location_description: string | null;
  serial_number: string | null;
  adapter_type: string;
  status: string;
  auto_sync: boolean;
  sync_interval_minutes: number;
  webhook_token: string | null;
  webhook_url: string | null;
  last_sync_at: string | null;
  branch?: { public_id: string; name: string } | null;
  branch_public_id: string | null;
  attendance_records_count?: number;
  sync_logs_count?: number;
  created_at: string;
  updated_at: string;
}

interface SyncLog {
  public_id: string;
  status: string;
  triggered_by: string;
  events_found: number;
  events_processed: number;
  events_failed: number;
  error_message: string | null;
  duration_ms: number | null;
  started_at: string;
  completed_at: string | null;
  created_at: string;
}

interface DeviceEvent {
  public_id: string;
  employee_name: string;
  employee_code: string | null;
  date: string;
  check_in: string | null;
  check_out: string | null;
  source: string;
  status: string;
  confidence_score: number;
  created_at: string;
}

export default function DeviceDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const queryClient = useQueryClient();
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [syncLogPage, setSyncLogPage] = useState(1);
  const [eventPage, setEventPage] = useState(1);

  const { data: device, isLoading } = useQuery<Device>({
    queryKey: ['devices', id],
    queryFn: async () => (await apiClient.get(`/devices/${id}`)).data,
  });

  const { data: syncLogs, isLoading: syncLogsLoading } = useQuery({
    queryKey: ['devices', id, 'sync-logs', syncLogPage],
    queryFn: async () => (await apiClient.get(`/devices/${id}/sync-logs`, { params: { page: syncLogPage, per_page: 10 } })).data,
    enabled: !!device,
  });

  const { data: events, isLoading: eventsLoading } = useQuery({
    queryKey: ['devices', id, 'events', eventPage],
    queryFn: async () => (await apiClient.get(`/devices/${id}/events`, { params: { page: eventPage, per_page: 15 } })).data,
    enabled: !!device,
  });

  const pullMutation = useMutation({
    mutationFn: async () => (await apiClient.post(`/devices/${id}/pull`)).data,
    onSuccess: () => {
      toast.success('Pull initiated — events will sync shortly');
      queryClient.invalidateQueries({ queryKey: ['devices', id] });
    },
    onError: () => toast.error('Pull failed'),
  });

  const testMutation = useMutation({
    mutationFn: async () => (await apiClient.get(`/devices/${id}/status`)).data,
    onSuccess: (data) => {
      const s = (data as { status?: string })?.status;
      if (s === 'online') toast.success('Device is online and responding');
      else toast.error(`Device is ${s}`);
      queryClient.invalidateQueries({ queryKey: ['devices', id] });
    },
    onError: () => toast.error('Connection test failed'),
  });

  const regenTokenMutation = useMutation({
    mutationFn: async () => (await apiClient.post(`/devices/${id}/regenerate-token`)).data,
    onSuccess: () => {
      toast.success('Webhook token regenerated');
      queryClient.invalidateQueries({ queryKey: ['devices', id] });
    },
    onError: () => toast.error('Failed to regenerate token'),
  });

  const deleteMutation = useMutation({
    mutationFn: async () => { await apiClient.delete(`/devices/${id}`); },
    onSuccess: () => { toast.success('Device deleted'); window.location.href = '/devices'; },
    onError: () => toast.error('Failed to delete device'),
  });

  const updateMutation = useMutation({
    mutationFn: async (data: Record<string, unknown>) => (await apiClient.put(`/devices/${id}`, data)).data,
    onSuccess: () => {
      toast.success('Device updated');
      setEditOpen(false);
      queryClient.invalidateQueries({ queryKey: ['devices', id] });
    },
    onError: () => toast.error('Failed to update'),
  });

  if (isLoading) {
    return (
      <RoleGate minRole="hr_admin">
        <div className="space-y-6">
          <Skeleton className="h-8 w-48" />
          <div className="grid gap-4 sm:grid-cols-3"><Skeleton className="h-32" /><Skeleton className="h-32" /><Skeleton className="h-32" /></div>
          <Skeleton className="h-64" />
        </div>
      </RoleGate>
    );
  }

  if (!device) {
    return <div className="py-16 text-center text-muted-foreground">Device not found</div>;
  }

  const isOnline = device.status === 'online';
  const syncLogItems: SyncLog[] = syncLogs?.data ?? [];
  const eventItems: DeviceEvent[] = events?.data ?? [];

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="sm" asChild>
            <Link href="/devices"><ArrowLeft className="mr-2 h-4 w-4" /> All Devices</Link>
          </Button>
        </div>

        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold text-foreground">{device.name}</h1>
              <Badge variant="outline" className={STATUS_STYLES[device.status] ?? ''}>
                <span className={`mr-1.5 inline-block h-1.5 w-1.5 rounded-full ${isOnline ? 'bg-green-500 animate-pulse' : device.status === 'error' ? 'bg-red-500' : 'bg-gray-400'}`} />
                {device.status}
              </Badge>
            </div>
            <p className="mt-1 text-sm text-muted-foreground">
              {ADAPTER_LABELS[device.adapter_type] ?? device.adapter_type}
              {device.location_description && ` — ${device.location_description}`}
              {device.branch?.name && ` — ${device.branch.name}`}
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={() => testMutation.mutate()} disabled={testMutation.isPending}>
              {testMutation.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Signal className="mr-2 h-4 w-4" />}
              Test
            </Button>
            <Button variant="outline" size="sm" onClick={() => pullMutation.mutate()} disabled={pullMutation.isPending}>
              {pullMutation.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <RefreshCw className="mr-2 h-4 w-4" />}
              Pull
            </Button>
            <Button variant="outline" size="sm" onClick={() => setEditOpen(true)}>
              <Pencil className="mr-2 h-4 w-4" /> Edit
            </Button>
            <Button variant="destructive" size="sm" onClick={() => setDeleteOpen(true)}>
              <Trash2 className="mr-2 h-4 w-4" /> Delete
            </Button>
          </div>
        </div>

        {/* Info Cards */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <InfoCard icon={Activity} label="Total Records" value={device.attendance_records_count?.toLocaleString() ?? '0'} />
          <InfoCard icon={RefreshCw} label="Total Syncs" value={device.sync_logs_count?.toLocaleString() ?? '0'} />
          <InfoCard icon={Clock} label="Last Sync" value={device.last_sync_at ? timeAgo(device.last_sync_at) : 'Never'} />
          <InfoCard icon={isOnline ? Wifi : WifiOff} label="Auto-Sync" value={device.auto_sync ? `Every ${device.sync_interval_minutes}m` : 'Disabled'} />
        </div>

        {/* Webhook URL */}
        {device.webhook_token && device.adapter_type !== 'mock' && (
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-sm">Webhook Configuration</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-3">
                <div>
                  <Label className="text-xs text-muted-foreground">Webhook URL (configure this in your device)</Label>
                  <div className="mt-1 flex items-center gap-2">
                    <code className="flex-1 rounded border bg-muted/50 px-3 py-2 text-xs font-mono break-all">
                      {device.webhook_url ?? 'N/A'}
                    </code>
                    <Button
                      variant="outline"
                      size="icon"
                      className="h-8 w-8 shrink-0"
                      onClick={() => { navigator.clipboard.writeText(device.webhook_url ?? ''); toast.success('Copied'); }}
                    >
                      <Copy className="h-3 w-3" />
                    </Button>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => regenTokenMutation.mutate()}
                    disabled={regenTokenMutation.isPending}
                  >
                    <RotateCw className="mr-2 h-3 w-3" />
                    Regenerate Token
                  </Button>
                  <span className="text-xs text-muted-foreground">This will invalidate the current URL</span>
                </div>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Tabs: Sync History + Events */}
        <Tabs defaultValue="syncs">
          <TabsList>
            <TabsTrigger value="syncs">Sync History</TabsTrigger>
            <TabsTrigger value="events">Attendance Events</TabsTrigger>
          </TabsList>

          <TabsContent value="syncs" className="mt-4">
            <Card>
              <CardContent className="p-0">
                {syncLogsLoading ? (
                  <div className="space-y-2 p-4">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-12" />)}</div>
                ) : syncLogItems.length === 0 ? (
                  <div className="py-12 text-center text-sm text-muted-foreground">No sync history yet — pull records or wait for auto-sync</div>
                ) : (
                  <>
                    <div className="overflow-x-auto">
                      <table className="w-full">
                        <thead>
                          <tr className="border-b bg-muted/50">
                            <th className="px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground">Status</th>
                            <th className="px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground">Trigger</th>
                            <th className="hidden px-4 py-2 text-right text-xs font-medium uppercase text-muted-foreground sm:table-cell">Found</th>
                            <th className="px-4 py-2 text-right text-xs font-medium uppercase text-muted-foreground">Processed</th>
                            <th className="hidden px-4 py-2 text-right text-xs font-medium uppercase text-muted-foreground md:table-cell">Failed</th>
                            <th className="hidden px-4 py-2 text-right text-xs font-medium uppercase text-muted-foreground md:table-cell">Duration</th>
                            <th className="px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground">Time</th>
                          </tr>
                        </thead>
                        <tbody>
                          {syncLogItems.map((log) => (
                            <tr key={log.public_id} className="border-b last:border-0 hover:bg-muted/30">
                              <td className="px-4 py-3">
                                <div className="flex items-center gap-2 text-sm">
                                  {SYNC_STATUS_ICON[log.status] ?? null}
                                  <span className="capitalize">{log.status}</span>
                                </div>
                              </td>
                              <td className="px-4 py-3 text-sm capitalize text-muted-foreground">{log.triggered_by}</td>
                              <td className="hidden px-4 py-3 text-right text-sm tabular-nums text-muted-foreground sm:table-cell">{log.events_found}</td>
                              <td className="px-4 py-3 text-right text-sm tabular-nums font-medium">{log.events_processed}</td>
                              <td className="hidden px-4 py-3 text-right text-sm tabular-nums text-muted-foreground md:table-cell">
                                {log.events_failed > 0 ? <span className="text-red-500">{log.events_failed}</span> : '0'}
                              </td>
                              <td className="hidden px-4 py-3 text-right text-xs text-muted-foreground md:table-cell">
                                {log.duration_ms ? `${log.duration_ms}ms` : '—'}
                              </td>
                              <td className="px-4 py-3 text-xs text-muted-foreground">{timeAgo(log.started_at)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                    {syncLogs?.meta?.last_page > 1 && (
                      <div className="flex items-center justify-between border-t p-3">
                        <span className="text-xs text-muted-foreground">Page {syncLogPage}</span>
                        <div className="flex gap-1">
                          <Button variant="outline" size="icon" className="h-7 w-7" disabled={syncLogPage <= 1} onClick={() => setSyncLogPage((p) => p - 1)}>
                            <ChevronLeft className="h-4 w-4" />
                          </Button>
                          <Button variant="outline" size="icon" className="h-7 w-7" disabled={syncLogPage >= syncLogs.meta.last_page} onClick={() => setSyncLogPage((p) => p + 1)}>
                            <ChevronRight className="h-4 w-4" />
                          </Button>
                        </div>
                      </div>
                    )}
                  </>
                )}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="events" className="mt-4">
            <Card>
              <CardContent className="p-0">
                {eventsLoading ? (
                  <div className="space-y-2 p-4">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-12" />)}</div>
                ) : eventItems.length === 0 ? (
                  <div className="py-12 text-center text-sm text-muted-foreground">No attendance events from this device yet</div>
                ) : (
                  <>
                    <div className="overflow-x-auto">
                      <table className="w-full">
                        <thead>
                          <tr className="border-b bg-muted/50">
                            <th className="px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground">Employee</th>
                            <th className="px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground">Date</th>
                            <th className="hidden px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground sm:table-cell">Check In</th>
                            <th className="hidden px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground sm:table-cell">Check Out</th>
                            <th className="px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground">Status</th>
                            <th className="hidden px-4 py-2 text-right text-xs font-medium uppercase text-muted-foreground md:table-cell">Confidence</th>
                          </tr>
                        </thead>
                        <tbody>
                          {eventItems.map((ev) => (
                            <tr key={ev.public_id} className="border-b last:border-0 hover:bg-muted/30">
                              <td className="px-4 py-3">
                                <p className="text-sm font-medium">{ev.employee_name}</p>
                                {ev.employee_code && <p className="text-xs text-muted-foreground">{ev.employee_code}</p>}
                              </td>
                              <td className="px-4 py-3 text-sm text-muted-foreground">{ev.date}</td>
                              <td className="hidden px-4 py-3 text-sm text-muted-foreground sm:table-cell">
                                {ev.check_in ? new Date(ev.check_in).toLocaleTimeString() : '—'}
                              </td>
                              <td className="hidden px-4 py-3 text-sm text-muted-foreground sm:table-cell">
                                {ev.check_out ? new Date(ev.check_out).toLocaleTimeString() : '—'}
                              </td>
                              <td className="px-4 py-3">
                                <Badge variant="outline" className={STATUS_STYLES[ev.status] ?? 'border-0'}>
                                  {ev.status}
                                </Badge>
                              </td>
                              <td className="hidden px-4 py-3 text-right text-sm tabular-nums text-muted-foreground md:table-cell">
                                {ev.confidence_score}%
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                    {events?.meta?.last_page > 1 && (
                      <div className="flex items-center justify-between border-t p-3">
                        <span className="text-xs text-muted-foreground">Page {eventPage}</span>
                        <div className="flex gap-1">
                          <Button variant="outline" size="icon" className="h-7 w-7" disabled={eventPage <= 1} onClick={() => setEventPage((p) => p - 1)}>
                            <ChevronLeft className="h-4 w-4" />
                          </Button>
                          <Button variant="outline" size="icon" className="h-7 w-7" disabled={eventPage >= events.meta.last_page} onClick={() => setEventPage((p) => p + 1)}>
                            <ChevronRight className="h-4 w-4" />
                          </Button>
                        </div>
                      </div>
                    )}
                  </>
                )}
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>

        {/* Edit Dialog */}
        {editOpen && device && (
          <EditDeviceDialog
            device={device}
            open={editOpen}
            onOpenChange={setEditOpen}
            onSubmit={(data) => updateMutation.mutate(data)}
            isPending={updateMutation.isPending}
          />
        )}

        {/* Delete Confirmation */}
        <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Delete Device</DialogTitle>
              <DialogDescription>
                Are you sure you want to delete <strong>{device.name}</strong>? Attendance records from this device will be preserved but unlinked.
              </DialogDescription>
            </DialogHeader>
            <DialogFooter>
              <Button variant="outline" onClick={() => setDeleteOpen(false)}>Cancel</Button>
              <Button variant="destructive" onClick={() => deleteMutation.mutate()} disabled={deleteMutation.isPending}>
                {deleteMutation.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Delete
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}

function InfoCard({ icon: Icon, label, value }: { icon: React.ComponentType<{ className?: string }>; label: string; value: string }) {
  return (
    <Card>
      <CardContent className="flex items-center gap-3 p-4">
        <Icon className="h-5 w-5 text-muted-foreground" />
        <div>
          <p className="text-xs text-muted-foreground">{label}</p>
          <p className="text-lg font-semibold text-foreground">{value}</p>
        </div>
      </CardContent>
    </Card>
  );
}

function EditDeviceDialog({ device, open, onOpenChange, onSubmit, isPending }: {
  device: Device;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSubmit: (data: Record<string, unknown>) => void;
  isPending: boolean;
}) {
  const [form, setForm] = useState({
    name: device.name,
    location_description: device.location_description ?? '',
    serial_number: device.serial_number ?? '',
    auto_sync: device.auto_sync,
    sync_interval_minutes: String(device.sync_interval_minutes),
  });

  const set = (key: string, value: string | boolean) => setForm((p) => ({ ...p, [key]: value }));

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader><DialogTitle>Edit Device</DialogTitle></DialogHeader>
        <form onSubmit={(e) => {
          e.preventDefault();
          onSubmit({
            name: form.name,
            location_description: form.location_description || null,
            serial_number: form.serial_number || null,
            auto_sync: form.auto_sync,
            sync_interval_minutes: parseInt(form.sync_interval_minutes, 10) || 5,
          });
        }} className="space-y-4">
          <div>
            <Label>Device Name</Label>
            <Input value={form.name} onChange={(e) => set('name', e.target.value)} required className="mt-1" />
          </div>
          <div>
            <Label>Location Description</Label>
            <Input value={form.location_description} onChange={(e) => set('location_description', e.target.value)} placeholder="e.g. Ground floor, east wing" className="mt-1" />
          </div>
          <div>
            <Label>Serial Number</Label>
            <Input value={form.serial_number} onChange={(e) => set('serial_number', e.target.value)} className="mt-1" />
          </div>
          <div className="flex items-center justify-between rounded-lg border p-3">
            <div>
              <p className="text-sm font-medium">Auto-Sync</p>
              <p className="text-xs text-muted-foreground">Automatically pull records on schedule</p>
            </div>
            <Switch checked={form.auto_sync} onCheckedChange={(v) => set('auto_sync', v)} />
          </div>
          {form.auto_sync && (
            <div>
              <Label>Sync Interval</Label>
              <Select value={form.sync_interval_minutes} onValueChange={(v) => set('sync_interval_minutes', v)}>
                <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="1">Every 1 minute</SelectItem>
                  <SelectItem value="5">Every 5 minutes</SelectItem>
                  <SelectItem value="10">Every 10 minutes</SelectItem>
                  <SelectItem value="15">Every 15 minutes</SelectItem>
                  <SelectItem value="30">Every 30 minutes</SelectItem>
                  <SelectItem value="60">Every 1 hour</SelectItem>
                </SelectContent>
              </Select>
            </div>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
            <Button type="submit" disabled={isPending}>
              {isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Save Changes
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
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
