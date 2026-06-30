'use client';

import { useState } from 'react';
import { FileEdit, Plus, Loader2, Check, X, AlertCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from '@/components/ui/dialog';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { EmptyState } from '@/components/shared/empty-state';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import { usePermissions } from '@/lib/hooks/usePermissions';
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
  employee?: { name: string; employee_code?: string };
}

export default function CorrectionsPage() {
  const { isSupervisor } = usePermissions();
  const [requestOpen, setRequestOpen] = useState(false);

  return (
    <div className="space-y-6">
      <PageHeader
        title="Attendance Corrections"
        description="Submit and review corrections for attendance records"
        actions={
          <Button onClick={() => setRequestOpen(true)}>
            <Plus className="mr-2 h-4 w-4" /> Request Correction
          </Button>
        }
      />

      <Tabs defaultValue="mine">
        <TabsList>
          <TabsTrigger value="mine">My Requests</TabsTrigger>
          {isSupervisor && <TabsTrigger value="pending">Pending Reviews</TabsTrigger>}
        </TabsList>

        <TabsContent value="mine" className="mt-4"><MyRequestsTab /></TabsContent>
        {isSupervisor && (
          <TabsContent value="pending" className="mt-4"><PendingReviewsTab /></TabsContent>
        )}
      </Tabs>

      <RequestDialog open={requestOpen} onClose={() => setRequestOpen(false)} />
    </div>
  );
}

// ── MY REQUESTS ────────────────────────────────────────────────

function MyRequestsTab() {
  const { data, isLoading } = useQuery({
    queryKey: ['corrections', 'mine'],
    queryFn: async () => (await apiClient.get('/attendance/corrections')).data,
  });

  const corrections: Correction[] = data?.data ?? [];

  if (isLoading) {
    return <div className="space-y-3">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-16 w-full" />)}</div>;
  }

  if (corrections.length === 0) {
    return (
      <EmptyState
        icon={FileEdit}
        title="No correction requests"
        description="Submit a correction request if your attendance record is incorrect"
      />
    );
  }

  return (
    <Card>
      <CardContent className="p-0">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="border-b bg-muted/50">
                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">Date</th>
                <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground sm:table-cell">Corrected In</th>
                <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground sm:table-cell">Corrected Out</th>
                <th className="hidden max-w-xs px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground md:table-cell">Reason</th>
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
  );
}

// ── PENDING REVIEWS (supervisor) ───────────────────────────────

function PendingReviewsTab() {
  const queryClient = useQueryClient();
  const [rejectFor, setRejectFor] = useState<Correction | null>(null);
  const [rejectReason, setRejectReason] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['corrections', 'pending'],
    queryFn: async () => (await apiClient.get('/attendance/corrections/pending')).data,
  });

  const approveMut = useMutation({
    mutationFn: async (publicId: string) => {
      const { data } = await apiClient.put(`/attendance/corrections/${publicId}/approve`);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['corrections'] });
      queryClient.invalidateQueries({ queryKey: ['attendance'] });
      toast.success('Correction approved');
    },
    onError: () => toast.error('Approve failed'),
  });

  const rejectMut = useMutation({
    mutationFn: async ({ publicId, reason }: { publicId: string; reason: string }) => {
      const { data } = await apiClient.put(`/attendance/corrections/${publicId}/reject`, { reason });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['corrections'] });
      toast.success('Correction rejected');
      setRejectFor(null);
      setRejectReason('');
    },
    onError: () => toast.error('Reject failed'),
  });

  const items: Correction[] = data?.data ?? [];

  if (isLoading) {
    return <div className="space-y-3">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-20" />)}</div>;
  }

  if (items.length === 0) {
    return (
      <EmptyState
        icon={Check}
        title="All caught up"
        description="No correction requests pending your review"
      />
    );
  }

  return (
    <>
      <div className="space-y-3">
        {items.map((c) => {
          const isProcessing = (approveMut.isPending && approveMut.variables === c.public_id)
            || (rejectMut.isPending && rejectMut.variables?.publicId === c.public_id);
          return (
            <Card key={c.public_id}>
              <CardContent className="p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <div className="flex-1 min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="font-semibold text-foreground">{c.employee?.name ?? 'Employee'}</p>
                      {c.employee?.employee_code && (
                        <Badge variant="outline" className="text-[10px] font-mono">{c.employee.employee_code}</Badge>
                      )}
                      <Badge variant="outline" className="text-[10px]">{c.date}</Badge>
                    </div>
                    <div className="mt-2 grid gap-2 text-xs sm:grid-cols-2">
                      <div className="flex items-baseline gap-2">
                        <span className="text-muted-foreground">Original:</span>
                        <span className="font-mono">{c.original_check_in ?? '—'} → {c.original_check_out ?? '—'}</span>
                      </div>
                      <div className="flex items-baseline gap-2">
                        <span className="text-muted-foreground">Requested:</span>
                        <span className="font-mono font-medium text-foreground">
                          {c.corrected_check_in ?? '—'} → {c.corrected_check_out ?? '—'}
                        </span>
                      </div>
                    </div>
                    <div className="mt-2 flex items-start gap-2 rounded-lg bg-muted/30 p-2">
                      <AlertCircle className="mt-0.5 h-3 w-3 shrink-0 text-muted-foreground" />
                      <p className="text-xs text-foreground">{c.reason}</p>
                    </div>
                  </div>
                  <div className="flex gap-2 sm:flex-col sm:items-stretch">
                    <Button
                      size="sm"
                      variant="outline"
                      className="text-green-600 hover:bg-green-50 hover:text-green-700 dark:hover:bg-green-950"
                      onClick={() => approveMut.mutate(c.public_id)}
                      disabled={isProcessing}
                    >
                      {approveMut.isPending && approveMut.variables === c.public_id
                        ? <Loader2 className="h-3 w-3 animate-spin" />
                        : <><Check className="mr-1 h-3 w-3" /> Approve</>}
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      className="text-red-600 hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-950"
                      onClick={() => setRejectFor(c)}
                      disabled={isProcessing}
                    >
                      <X className="mr-1 h-3 w-3" /> Reject
                    </Button>
                  </div>
                </div>
              </CardContent>
            </Card>
          );
        })}
      </div>

      <Dialog open={!!rejectFor} onOpenChange={(open) => { if (!open) { setRejectFor(null); setRejectReason(''); } }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Reject Correction</DialogTitle>
            <DialogDescription>
              Tell {rejectFor?.employee?.name ?? 'the employee'} why this correction is being rejected.
            </DialogDescription>
          </DialogHeader>
          <Textarea
            value={rejectReason}
            onChange={(e) => setRejectReason(e.target.value)}
            placeholder="Reason for rejection"
            rows={3}
            required
          />
          <DialogFooter>
            <Button variant="outline" onClick={() => setRejectFor(null)}>Cancel</Button>
            <Button
              variant="destructive"
              onClick={() => rejectFor && rejectMut.mutate({ publicId: rejectFor.public_id, reason: rejectReason })}
              disabled={rejectMut.isPending || !rejectReason.trim()}
            >
              {rejectMut.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Reject Correction
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

// ── REQUEST DIALOG ─────────────────────────────────────────────

function RequestDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ date: '', corrected_check_in: '', corrected_check_out: '', reason: '' });

  const submit = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post('/attendance/corrections', form);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['corrections'] });
      toast.success('Correction request submitted');
      onClose();
      setForm({ date: '', corrected_check_in: '', corrected_check_out: '', reason: '' });
    },
    onError: () => toast.error('Failed to submit correction'),
  });

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent>
        <DialogHeader><DialogTitle>Request Attendance Correction</DialogTitle></DialogHeader>
        <form onSubmit={(e) => { e.preventDefault(); submit.mutate(); }} className="space-y-4">
          <div>
            <Label>Date *</Label>
            <Input type="date" value={form.date} onChange={(e) => setForm((p) => ({ ...p, date: e.target.value }))} required className="mt-1" />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label>Correct Check In</Label>
              <Input type="time" value={form.corrected_check_in} onChange={(e) => setForm((p) => ({ ...p, corrected_check_in: e.target.value }))} className="mt-1" />
            </div>
            <div>
              <Label>Correct Check Out</Label>
              <Input type="time" value={form.corrected_check_out} onChange={(e) => setForm((p) => ({ ...p, corrected_check_out: e.target.value }))} className="mt-1" />
            </div>
          </div>
          <div>
            <Label>Reason *</Label>
            <Textarea value={form.reason} onChange={(e) => setForm((p) => ({ ...p, reason: e.target.value }))} required placeholder="Explain why the correction is needed" className="mt-1" />
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
            <Button type="submit" disabled={submit.isPending}>
              {submit.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Submit Request
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
