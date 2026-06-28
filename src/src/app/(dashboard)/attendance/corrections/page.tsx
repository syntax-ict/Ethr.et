'use client';

import { useState } from 'react';
import { FileEdit, Plus, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { EmptyState } from '@/components/shared/empty-state';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import { toast } from 'sonner';

interface Correction {
  public_id: string;
  date: string;
  original_check_in: string | null;
  original_check_out: string | null;
  corrected_check_in: string | null;
  corrected_check_out: string | null;
  reason: string;
  status: string;
  created_at: string;
}

export default function CorrectionsPage() {
  const queryClient = useQueryClient();
  const [dialogOpen, setDialogOpen] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['corrections'],
    queryFn: async () => {
      const { data } = await apiClient.get('/attendance/corrections');
      return data;
    },
  });

  const [form, setForm] = useState({
    date: '',
    corrected_check_in: '',
    corrected_check_out: '',
    reason: '',
  });

  const submitCorrection = useMutation({
    mutationFn: async (payload: typeof form) => {
      const { data } = await apiClient.post('/attendance/corrections', payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['corrections'] });
      toast.success('Correction request submitted');
      setDialogOpen(false);
      setForm({ date: '', corrected_check_in: '', corrected_check_out: '', reason: '' });
    },
    onError: () => toast.error('Failed to submit correction'),
  });

  const corrections: Correction[] = data?.data ?? [];

  return (
    <div className="space-y-6">
      <PageHeader
        title="Attendance Corrections"
        description="Request corrections for attendance records"
        actions={
          <Button onClick={() => setDialogOpen(true)}>
            <Plus className="mr-2 h-4 w-4" />
            Request Correction
          </Button>
        }
      />

      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-16 w-full" />
          ))}
        </div>
      ) : corrections.length === 0 ? (
        <EmptyState
          icon={FileEdit}
          title="No corrections"
          description="Submit a correction request if your attendance record is incorrect"
        />
      ) : (
        <Card>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b bg-muted/50">
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">Date</th>
                    <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground sm:table-cell">Corrected In</th>
                    <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground sm:table-cell">Corrected Out</th>
                    <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground md:table-cell">Reason</th>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {corrections.map((c) => (
                    <tr key={c.public_id} className="border-b last:border-0 hover:bg-muted/30">
                      <td className="px-4 py-3 text-sm font-medium text-foreground">{c.date}</td>
                      <td className="hidden px-4 py-3 text-sm text-muted-foreground sm:table-cell">{c.corrected_check_in ?? '—'}</td>
                      <td className="hidden px-4 py-3 text-sm text-muted-foreground sm:table-cell">{c.corrected_check_out ?? '—'}</td>
                      <td className="hidden max-w-[200px] truncate px-4 py-3 text-sm text-muted-foreground md:table-cell">{c.reason}</td>
                      <td className="px-4 py-3"><StatusBadge status={c.status} /></td>
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
            <DialogTitle>Request Attendance Correction</DialogTitle>
          </DialogHeader>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              submitCorrection.mutate(form);
            }}
            className="space-y-4"
          >
            <div>
              <Label htmlFor="corr-date">Date</Label>
              <Input id="corr-date" type="date" value={form.date} onChange={(e) => setForm(p => ({ ...p, date: e.target.value }))} required className="mt-1" />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label htmlFor="corr-in">Correct Check In</Label>
                <Input id="corr-in" type="time" value={form.corrected_check_in} onChange={(e) => setForm(p => ({ ...p, corrected_check_in: e.target.value }))} className="mt-1" />
              </div>
              <div>
                <Label htmlFor="corr-out">Correct Check Out</Label>
                <Input id="corr-out" type="time" value={form.corrected_check_out} onChange={(e) => setForm(p => ({ ...p, corrected_check_out: e.target.value }))} className="mt-1" />
              </div>
            </div>
            <div>
              <Label htmlFor="corr-reason">Reason</Label>
              <Textarea id="corr-reason" value={form.reason} onChange={(e) => setForm(p => ({ ...p, reason: e.target.value }))} required placeholder="Explain why the correction is needed" className="mt-1" />
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>Cancel</Button>
              <Button type="submit" disabled={submitCorrection.isPending}>
                {submitCorrection.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Submit Request
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
