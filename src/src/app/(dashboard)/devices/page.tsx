'use client';

import { useState } from 'react';
import { Fingerprint, Plus, Wifi, WifiOff, RefreshCw, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { PageHeader } from '@/components/shared/page-header';
import { EmptyState } from '@/components/shared/empty-state';
import { RoleGate } from '@/components/shared/role-gate';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import { toast } from 'sonner';

interface Device {
  public_id: string;
  name: string;
  type: string;
  brand: string;
  model?: string;
  ip_address?: string;
  status: string;
  branch?: { name: string };
  last_seen_at: string | null;
}

export default function DevicesPage() {
  const queryClient = useQueryClient();
  const [createOpen, setCreateOpen] = useState(false);
  const [form, setForm] = useState({ name: '', type: 'biometric', brand: 'hikvision', ip_address: '', branch_public_id: '' });

  const { data, isLoading } = useQuery({
    queryKey: ['devices'],
    queryFn: async () => {
      const { data } = await apiClient.get('/devices');
      return data;
    },
  });

  const { data: branches } = useQuery({
    queryKey: ['org', 'branches'],
    queryFn: async () => {
      const { data } = await apiClient.get('/organization/branches');
      return data;
    },
  });

  const createDevice = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post('/devices', form);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['devices'] });
      toast.success('Device added');
      setCreateOpen(false);
      setForm({ name: '', type: 'biometric', brand: 'hikvision', ip_address: '', branch_public_id: '' });
    },
    onError: () => toast.error('Failed to add device'),
  });

  const pullDevice = useMutation({
    mutationFn: async (id: string) => {
      const { data } = await apiClient.post(`/devices/${id}/pull`);
      return data;
    },
    onSuccess: () => toast.success('Pull initiated'),
    onError: () => toast.error('Pull failed'),
  });

  const devices: Device[] = data?.data ?? [];

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title="Devices"
          description="Biometric attendance devices and integrations"
          actions={
            <Button onClick={() => setCreateOpen(true)}>
              <Plus className="mr-2 h-4 w-4" /> Add Device
            </Button>
          }
        />

        {isLoading ? (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-40" />)}
          </div>
        ) : devices.length === 0 ? (
          <EmptyState
            icon={Fingerprint}
            title="No devices registered"
            description="Add a biometric device to start syncing attendance"
            action={<Button onClick={() => setCreateOpen(true)}><Plus className="mr-2 h-4 w-4" /> Add Device</Button>}
          />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {devices.map((d) => {
              const isOnline = d.status === 'online';
              return (
                <Card key={d.public_id}>
                  <CardContent className="p-4">
                    <div className="flex items-start justify-between">
                      <div className="flex items-center gap-3">
                        <div className={`flex h-10 w-10 items-center justify-center rounded-xl ${isOnline ? 'bg-green-100 dark:bg-green-950' : 'bg-gray-100 dark:bg-gray-800'}`}>
                          {isOnline
                            ? <Wifi className="h-5 w-5 text-green-600 dark:text-green-400" />
                            : <WifiOff className="h-5 w-5 text-gray-400" />}
                        </div>
                        <div>
                          <p className="font-semibold text-foreground">{d.name}</p>
                          <p className="text-xs text-muted-foreground capitalize">{d.brand} · {d.type}</p>
                        </div>
                      </div>
                      <Badge variant="outline" className={isOnline ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300 border-0' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400 border-0'}>
                        {d.status}
                      </Badge>
                    </div>

                    <div className="mt-4 space-y-1 text-xs">
                      {d.ip_address && <p className="text-muted-foreground"><span className="font-mono">{d.ip_address}</span></p>}
                      {d.branch?.name && <p className="text-muted-foreground">📍 {d.branch.name}</p>}
                      {d.last_seen_at && <p className="text-muted-foreground">Last seen: {new Date(d.last_seen_at).toLocaleString()}</p>}
                    </div>

                    <Button
                      variant="outline"
                      size="sm"
                      className="mt-3 w-full"
                      onClick={() => pullDevice.mutate(d.public_id)}
                      disabled={pullDevice.isPending}
                    >
                      <RefreshCw className="mr-2 h-3 w-3" />
                      Pull Records
                    </Button>
                  </CardContent>
                </Card>
              );
            })}
          </div>
        )}

        <Dialog open={createOpen} onOpenChange={setCreateOpen}>
          <DialogContent>
            <DialogHeader><DialogTitle>Add Device</DialogTitle></DialogHeader>
            <form onSubmit={(e) => { e.preventDefault(); createDevice.mutate(); }} className="space-y-4">
              <div>
                <Label>Device Name</Label>
                <Input value={form.name} onChange={(e) => setForm(p => ({ ...p, name: e.target.value }))} required placeholder="e.g. Main Entrance" className="mt-1" />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>Type</Label>
                  <Select value={form.type} onValueChange={(v) => setForm(p => ({ ...p, type: v }))}>
                    <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="biometric">Biometric</SelectItem>
                      <SelectItem value="face">Face</SelectItem>
                      <SelectItem value="card">Card Reader</SelectItem>
                      <SelectItem value="kiosk">Kiosk</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label>Brand</Label>
                  <Select value={form.brand} onValueChange={(v) => setForm(p => ({ ...p, brand: v }))}>
                    <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="hikvision">Hikvision</SelectItem>
                      <SelectItem value="zkteco">ZKTeco</SelectItem>
                      <SelectItem value="suprema">Suprema</SelectItem>
                      <SelectItem value="generic">Generic</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
              <div>
                <Label>IP Address</Label>
                <Input value={form.ip_address} onChange={(e) => setForm(p => ({ ...p, ip_address: e.target.value }))} placeholder="192.168.1.100" className="mt-1" />
              </div>
              {branches?.data && (
                <div>
                  <Label>Branch</Label>
                  <Select value={form.branch_public_id} onValueChange={(v) => setForm(p => ({ ...p, branch_public_id: v }))}>
                    <SelectTrigger className="mt-1"><SelectValue placeholder="Select branch" /></SelectTrigger>
                    <SelectContent>
                      {branches.data.map((b: { public_id: string; name: string }) => (
                        <SelectItem key={b.public_id} value={b.public_id}>{b.name}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              )}
              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setCreateOpen(false)}>Cancel</Button>
                <Button type="submit" disabled={createDevice.isPending}>
                  {createDevice.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                  Add Device
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
