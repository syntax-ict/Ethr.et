'use client';

import { useState } from 'react';
import { KeyRound, Plus, Trash2, Copy, Loader2 } from 'lucide-react';
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

interface ApiKey {
  public_id: string;
  name: string;
  key_prefix: string;
  abilities: string[];
  is_active: boolean;
  last_used_at: string | null;
  expires_at: string | null;
  created_at: string;
}

export default function ApiKeysPage() {
  const queryClient = useQueryClient();
  const [createOpen, setCreateOpen] = useState(false);
  const [newKey, setNewKey] = useState<string | null>(null);
  const [form, setForm] = useState({ name: '', abilities: ['read'] as string[] });

  const { data, isLoading } = useQuery({
    queryKey: ['api-keys'],
    queryFn: async () => {
      const { data } = await apiClient.get('/api-keys');
      return data;
    },
  });

  const createKey = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post('/api-keys', form);
      return data;
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['api-keys'] });
      setNewKey(data.key);
      setCreateOpen(false);
      setForm({ name: '', abilities: ['read'] });
      toast.success('API key created — copy it now, it won\'t be shown again');
    },
    onError: () => toast.error('Failed to create API key'),
  });

  const revokeKey = useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`/api-keys/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['api-keys'] });
      toast.success('Key revoked');
    },
  });

  const keys: ApiKey[] = data?.keys ?? [];

  function toggleAbility(ability: string) {
    setForm((p) => ({
      ...p,
      abilities: p.abilities.includes(ability)
        ? p.abilities.filter((a) => a !== ability)
        : [...p.abilities, ability],
    }));
  }

  function copyKey() {
    if (newKey) {
      navigator.clipboard.writeText(newKey);
      toast.success('Key copied to clipboard');
    }
  }

  return (
    <RoleGate minRole="tenant_admin">
      <div className="space-y-6">
        <PageHeader
          title="API Keys"
          description="Manage API keys for external integrations"
          actions={
            <Button onClick={() => setCreateOpen(true)}>
              <Plus className="mr-2 h-4 w-4" /> Create Key
            </Button>
          }
        />

        {newKey && (
          <Card className="border-2 border-amber-300 bg-amber-50 dark:bg-amber-950/30">
            <CardContent className="p-4">
              <p className="mb-2 text-sm font-semibold text-amber-900 dark:text-amber-300">
                Save this key now — it will not be shown again
              </p>
              <div className="flex items-center gap-2">
                <code className="flex-1 rounded bg-background px-3 py-2 text-xs font-mono break-all">{newKey}</code>
                <Button size="sm" variant="outline" onClick={copyKey}>
                  <Copy className="h-4 w-4" />
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setNewKey(null)}>Dismiss</Button>
              </div>
            </CardContent>
          </Card>
        )}

        {isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-20 w-full" />)}
          </div>
        ) : keys.length === 0 ? (
          <EmptyState icon={KeyRound} title="No API keys" description="Create your first API key to enable integrations" />
        ) : (
          <Card>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b bg-muted/50">
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">Name</th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">Prefix</th>
                      <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground sm:table-cell">Abilities</th>
                      <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground md:table-cell">Created</th>
                      <th className="px-4 py-3 text-right text-xs font-medium uppercase text-muted-foreground">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {keys.map((k) => (
                      <tr key={k.public_id} className="border-b last:border-0 hover:bg-muted/30">
                        <td className="px-4 py-3 text-sm font-medium text-foreground">{k.name}</td>
                        <td className="px-4 py-3 text-sm font-mono text-muted-foreground">{k.key_prefix}...</td>
                        <td className="hidden px-4 py-3 sm:table-cell">
                          <div className="flex flex-wrap gap-1">
                            {k.abilities.map((a) => <Badge key={a} variant="outline" className="text-[10px]">{a}</Badge>)}
                          </div>
                        </td>
                        <td className="hidden px-4 py-3 text-sm text-muted-foreground md:table-cell">
                          {new Date(k.created_at).toLocaleDateString()}
                        </td>
                        <td className="px-4 py-3 text-right">
                          <Button variant="ghost" size="sm" onClick={() => revokeKey.mutate(k.public_id)}>
                            <Trash2 className="h-4 w-4 text-destructive" />
                          </Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        )}

        <Dialog open={createOpen} onOpenChange={setCreateOpen}>
          <DialogContent>
            <DialogHeader><DialogTitle>Create API Key</DialogTitle></DialogHeader>
            <form onSubmit={(e) => { e.preventDefault(); createKey.mutate(); }} className="space-y-4">
              <div>
                <Label>Key Name</Label>
                <Input value={form.name} onChange={(e) => setForm(p => ({ ...p, name: e.target.value }))} required placeholder="e.g. Mobile App Integration" className="mt-1" />
              </div>
              <div>
                <Label>Abilities</Label>
                <div className="mt-2 grid grid-cols-2 gap-2">
                  {['read', 'write', 'employees', 'attendance', 'leave', 'payroll', 'reports'].map((a) => (
                    <label key={a} className="flex cursor-pointer items-center gap-2 rounded-lg border p-2 hover:bg-muted/50">
                      <input type="checkbox" checked={form.abilities.includes(a)} onChange={() => toggleAbility(a)} className="h-4 w-4 rounded" />
                      <span className="text-sm capitalize">{a}</span>
                    </label>
                  ))}
                </div>
              </div>
              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setCreateOpen(false)}>Cancel</Button>
                <Button type="submit" disabled={createKey.isPending || form.abilities.length === 0}>
                  {createKey.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
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
