'use client';

import { useState } from 'react';
import { Webhook, Plus, Trash2, Copy, Send, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { PageHeader } from '@/components/shared/page-header';
import { EmptyState } from '@/components/shared/empty-state';
import { RoleGate } from '@/components/shared/role-gate';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import { toast } from 'sonner';

interface WebhookEntry {
  public_id: string;
  url: string;
  events: string[];
  is_active: boolean;
  failure_count: number;
  last_triggered_at: string | null;
  created_at: string;
}

const ALL_EVENTS = [
  'employee.created', 'employee.updated', 'employee.transitioned',
  'attendance.recorded', 'attendance.corrected',
  'leave.requested', 'leave.approved', 'leave.rejected',
  'payroll.processed', 'payroll.approved',
  'device.online', 'device.offline',
];

export default function WebhooksPage() {
  const queryClient = useQueryClient();
  const [createOpen, setCreateOpen] = useState(false);
  const [newSecret, setNewSecret] = useState<string | null>(null);
  const [form, setForm] = useState({ url: '', events: [] as string[] });

  const { data, isLoading } = useQuery({
    queryKey: ['webhooks'],
    queryFn: async () => {
      const { data } = await apiClient.get('/webhooks');
      return data;
    },
  });

  const createWebhook = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post('/webhooks', form);
      return data;
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['webhooks'] });
      setNewSecret(data.secret);
      setCreateOpen(false);
      setForm({ url: '', events: [] });
      toast.success('Webhook created — save the secret now');
    },
    onError: () => toast.error('Failed to create webhook'),
  });

  const deleteWebhook = useMutation({
    mutationFn: async (id: string) => { await apiClient.delete(`/webhooks/${id}`); },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['webhooks'] });
      toast.success('Webhook deleted');
    },
  });

  const testWebhook = useMutation({
    mutationFn: async (id: string) => {
      const { data } = await apiClient.post(`/webhooks/${id}/test`);
      return data;
    },
    onSuccess: () => toast.success('Test event dispatched'),
    onError: () => toast.error('Failed to send test'),
  });

  const webhooks: WebhookEntry[] = data?.webhooks ?? [];

  function toggleEvent(event: string) {
    setForm((p) => ({
      ...p,
      events: p.events.includes(event) ? p.events.filter((e) => e !== event) : [...p.events, event],
    }));
  }

  function copySecret() {
    if (newSecret) {
      navigator.clipboard.writeText(newSecret);
      toast.success('Secret copied');
    }
  }

  return (
    <RoleGate minRole="tenant_admin">
      <div className="space-y-6">
        <PageHeader
          title="Webhooks"
          description="Receive HTTP notifications when events happen in your tenant"
          actions={
            <Button onClick={() => setCreateOpen(true)}>
              <Plus className="mr-2 h-4 w-4" /> Add Webhook
            </Button>
          }
        />

        {newSecret && (
          <Card className="border-2 border-amber-300 bg-amber-50 dark:bg-amber-950/30">
            <CardContent className="p-4">
              <p className="mb-2 text-sm font-semibold text-amber-900 dark:text-amber-300">
                Save this HMAC secret now — it won&apos;t be shown again
              </p>
              <div className="flex items-center gap-2">
                <code className="flex-1 rounded bg-background px-3 py-2 text-xs font-mono break-all">{newSecret}</code>
                <Button size="sm" variant="outline" onClick={copySecret}><Copy className="h-4 w-4" /></Button>
                <Button size="sm" variant="ghost" onClick={() => setNewSecret(null)}>Dismiss</Button>
              </div>
            </CardContent>
          </Card>
        )}

        {isLoading ? (
          <div className="space-y-3">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-24" />)}</div>
        ) : webhooks.length === 0 ? (
          <EmptyState icon={Webhook} title="No webhooks configured" description="Add a webhook to receive event notifications" />
        ) : (
          <div className="space-y-3">
            {webhooks.map((w) => (
              <Card key={w.public_id}>
                <CardContent className="p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <code className="text-sm font-mono text-foreground truncate">{w.url}</code>
                        <Badge variant="outline" className={w.is_active ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300 border-0' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400 border-0'}>
                          {w.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                        {w.failure_count > 0 && (
                          <Badge variant="outline" className="bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300 border-0">
                            {w.failure_count} failures
                          </Badge>
                        )}
                      </div>
                      <div className="mt-2 flex flex-wrap gap-1">
                        {w.events.map((e) => <Badge key={e} variant="outline" className="text-[10px]">{e}</Badge>)}
                      </div>
                      {w.last_triggered_at && (
                        <p className="mt-2 text-xs text-muted-foreground">
                          Last triggered: {new Date(w.last_triggered_at).toLocaleString()}
                        </p>
                      )}
                    </div>
                    <div className="flex gap-1">
                      <Button variant="ghost" size="sm" onClick={() => testWebhook.mutate(w.public_id)} disabled={testWebhook.isPending}>
                        <Send className="h-4 w-4" />
                      </Button>
                      <Button variant="ghost" size="sm" onClick={() => deleteWebhook.mutate(w.public_id)}>
                        <Trash2 className="h-4 w-4 text-destructive" />
                      </Button>
                    </div>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

        <Dialog open={createOpen} onOpenChange={setCreateOpen}>
          <DialogContent className="max-w-md">
            <DialogHeader><DialogTitle>Create Webhook</DialogTitle></DialogHeader>
            <form onSubmit={(e) => { e.preventDefault(); createWebhook.mutate(); }} className="space-y-4">
              <div>
                <Label>URL</Label>
                <Input type="url" value={form.url} onChange={(e) => setForm(p => ({ ...p, url: e.target.value }))} required placeholder="https://example.com/webhook" className="mt-1" />
              </div>
              <div>
                <Label>Events</Label>
                <div className="mt-2 max-h-60 overflow-y-auto space-y-1 rounded-lg border p-2">
                  {ALL_EVENTS.map((event) => (
                    <label key={event} className="flex cursor-pointer items-center gap-2 rounded p-1 hover:bg-muted/50">
                      <input type="checkbox" checked={form.events.includes(event)} onChange={() => toggleEvent(event)} className="h-4 w-4 rounded" />
                      <span className="text-sm font-mono">{event}</span>
                    </label>
                  ))}
                </div>
              </div>
              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setCreateOpen(false)}>Cancel</Button>
                <Button type="submit" disabled={createWebhook.isPending || form.events.length === 0}>
                  {createWebhook.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                  Create
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
