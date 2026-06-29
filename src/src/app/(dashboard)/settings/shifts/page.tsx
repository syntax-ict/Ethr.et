'use client';

import { useState } from 'react';
import { Clock, Plus, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import { PageHeader } from '@/components/shared/page-header';
import { EmptyState } from '@/components/shared/empty-state';
import { RoleGate } from '@/components/shared/role-gate';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import { toast } from 'sonner';

interface Shift {
  public_id: string;
  name: string;
  start_time: string;
  end_time: string;
  grace_minutes: number;
}

export default function ShiftsPage() {
  const queryClient = useQueryClient();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [form, setForm] = useState({
    name: '',
    start_time: '',
    end_time: '',
    grace_minutes: '15',
  });

  const { data, isLoading } = useQuery<{ data: Shift[] }>({
    queryKey: ['shifts'],
    queryFn: async () => {
      const { data } = await apiClient.get('/shifts');
      return data;
    },
  });

  const createShift = useMutation({
    mutationFn: async (payload: { name: string; start_time: string; end_time: string; grace_minutes: number }) => {
      const { data } = await apiClient.post('/shifts', payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['shifts'] });
      toast.success('Shift created');
      setDialogOpen(false);
      setForm({ name: '', start_time: '', end_time: '', grace_minutes: '15' });
    },
    onError: (err: unknown) => {
      const axiosError = err as { response?: { data?: { detail?: string } } };
      toast.error(axiosError.response?.data?.detail || 'Failed to create shift');
    },
  });

  function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    createShift.mutate({
      name: form.name,
      start_time: form.start_time,
      end_time: form.end_time,
      grace_minutes: parseInt(form.grace_minutes, 10),
    });
  }

  const shifts = data?.data ?? [];

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title="Shifts"
          description="Manage work shift schedules and grace periods"
          actions={
            <Button onClick={() => setDialogOpen(true)}>
              <Plus className="mr-2 h-4 w-4" />
              Add Shift
            </Button>
          }
        />

        {isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-12 w-full" />
            ))}
          </div>
        ) : shifts.length === 0 ? (
          <EmptyState
            icon={Clock}
            title="No shifts configured"
            description="Add shifts to define work schedules"
          />
        ) : (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Shifts</CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b bg-muted/50">
                      <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">Name</th>
                      <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">Start Time</th>
                      <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">End Time</th>
                      <th className="px-4 py-3 text-right text-sm font-medium text-muted-foreground">Grace (min)</th>
                    </tr>
                  </thead>
                  <tbody>
                    {shifts.map((shift) => (
                      <tr key={shift.public_id} className="border-b last:border-0 hover:bg-muted/30">
                        <td className="px-4 py-3 text-sm font-medium text-foreground">{shift.name}</td>
                        <td className="px-4 py-3 text-sm text-muted-foreground">{shift.start_time}</td>
                        <td className="px-4 py-3 text-sm text-muted-foreground">{shift.end_time}</td>
                        <td className="px-4 py-3 text-right text-sm text-muted-foreground">{shift.grace_minutes}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        )}

        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Add Shift</DialogTitle>
            </DialogHeader>
            <form onSubmit={handleCreate} className="space-y-4">
              <div>
                <Label htmlFor="shift_name">Name</Label>
                <Input
                  id="shift_name"
                  value={form.name}
                  onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))}
                  placeholder="e.g. Morning Shift"
                  required
                  className="mt-1"
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label htmlFor="shift_start">Start Time</Label>
                  <Input
                    id="shift_start"
                    type="time"
                    value={form.start_time}
                    onChange={(e) => setForm((p) => ({ ...p, start_time: e.target.value }))}
                    required
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label htmlFor="shift_end">End Time</Label>
                  <Input
                    id="shift_end"
                    type="time"
                    value={form.end_time}
                    onChange={(e) => setForm((p) => ({ ...p, end_time: e.target.value }))}
                    required
                    className="mt-1"
                  />
                </div>
              </div>
              <div>
                <Label htmlFor="shift_grace">Grace Period (minutes)</Label>
                <Input
                  id="shift_grace"
                  type="number"
                  min="0"
                  value={form.grace_minutes}
                  onChange={(e) => setForm((p) => ({ ...p, grace_minutes: e.target.value }))}
                  required
                  className="mt-1"
                />
              </div>
              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>
                  Cancel
                </Button>
                <Button type="submit" disabled={createShift.isPending}>
                  {createShift.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                  Add Shift
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
