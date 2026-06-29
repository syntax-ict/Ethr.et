'use client';

import { useState } from 'react';
import { CalendarDays, Plus, Loader2, Check, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Skeleton } from '@/components/ui/skeleton';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { EmptyState } from '@/components/shared/empty-state';
import { useLeaveBalance, useMyLeaveRequests, useLeaveTypes, useSubmitLeave, useTeamLeaveRequests, useApproveLeave, useRejectLeave, type LeaveBalance } from '@/features/leave/api';
import { usePermissions } from '@/lib/hooks/usePermissions';
import { toast } from 'sonner';

function leaveTypeName(lt: LeaveBalance['leave_type']): string {
  if (typeof lt === 'string') return lt;
  return lt?.name ?? 'Unknown';
}

export default function LeavePage() {
  const [dialogOpen, setDialogOpen] = useState(false);
  const { isSupervisor } = usePermissions();
  const { data: leaveTypes } = useLeaveTypes();

  const [leaveForm, setLeaveForm] = useState({ leave_type_public_id: '', start_date: '', end_date: '', reason: '' });
  const submitLeave = useSubmitLeave();

  function handleSubmitLeave(e: React.FormEvent) {
    e.preventDefault();
    submitLeave.mutate(leaveForm, {
      onSuccess: () => {
        toast.success('Leave request submitted');
        setDialogOpen(false);
        setLeaveForm({ leave_type_public_id: '', start_date: '', end_date: '', reason: '' });
      },
      onError: (err: unknown) => {
        const axiosError = err as { response?: { data?: { detail?: string } } };
        toast.error(axiosError.response?.data?.detail || 'Failed to submit leave request');
      },
    });
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Leave"
        description="Manage leave requests and balances"
        actions={
          <Button onClick={() => setDialogOpen(true)}>
            <Plus className="mr-2 h-4 w-4" /> Apply for Leave
          </Button>
        }
      />

      <Tabs defaultValue="my-leave">
        <TabsList>
          <TabsTrigger value="my-leave">My Leave</TabsTrigger>
          {isSupervisor && <TabsTrigger value="team">Team Leave</TabsTrigger>}
        </TabsList>

        <TabsContent value="my-leave" className="mt-4 space-y-6">
          <MyLeaveTab />
        </TabsContent>

        {isSupervisor && (
          <TabsContent value="team" className="mt-4">
            <TeamLeaveTab />
          </TabsContent>
        )}
      </Tabs>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent>
          <DialogHeader><DialogTitle>Apply for Leave</DialogTitle></DialogHeader>
          <form onSubmit={handleSubmitLeave} className="space-y-4">
            <div>
              <Label>Leave Type</Label>
              <Select value={leaveForm.leave_type_public_id} onValueChange={(v) => setLeaveForm(p => ({ ...p, leave_type_public_id: v }))}>
                <SelectTrigger className="mt-1"><SelectValue placeholder="Select leave type" /></SelectTrigger>
                <SelectContent>
                  {leaveTypes?.data?.map((lt) => (
                    <SelectItem key={lt.public_id} value={lt.public_id}>{lt.name}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label>Start Date</Label>
                <Input type="date" value={leaveForm.start_date} onChange={(e) => setLeaveForm(p => ({ ...p, start_date: e.target.value }))} required className="mt-1" />
              </div>
              <div>
                <Label>End Date</Label>
                <Input type="date" value={leaveForm.end_date} onChange={(e) => setLeaveForm(p => ({ ...p, end_date: e.target.value }))} required className="mt-1" />
              </div>
            </div>
            <div>
              <Label>Reason</Label>
              <Textarea value={leaveForm.reason} onChange={(e) => setLeaveForm(p => ({ ...p, reason: e.target.value }))} placeholder="Optional" className="mt-1" />
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>Cancel</Button>
              <Button type="submit" disabled={submitLeave.isPending}>
                {submitLeave.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Submit
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}

function MyLeaveTab() {
  const [page, setPage] = useState(1);
  const { data: balances, isLoading: balancesLoading } = useLeaveBalance();
  const { data: requests, isLoading: requestsLoading } = useMyLeaveRequests({ page });

  return (
    <>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {balancesLoading ? (
          Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-24" />)
        ) : balances && Array.isArray(balances) ? (
          balances.map((b, i) => (
            <Card key={i}>
              <CardContent className="p-4">
                <p className="text-sm text-muted-foreground">{leaveTypeName(b.leave_type)}</p>
                <p className="mt-1 text-2xl font-bold text-foreground">{b.remaining_days}</p>
                <p className="text-xs text-muted-foreground">{b.used_days} used of {b.entitled_days}</p>
              </CardContent>
            </Card>
          ))
        ) : null}
      </div>

      <Card>
        <CardHeader><CardTitle>My Leave Requests</CardTitle></CardHeader>
        <CardContent className="p-0">
          {requestsLoading ? (
            <div className="space-y-3 p-4">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-12 w-full" />)}</div>
          ) : !requests?.data?.length ? (
            <EmptyState icon={CalendarDays} title="No leave requests" description="Apply for leave to see your requests here" />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b bg-muted/50">
                    <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">Type</th>
                    <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">Dates</th>
                    <th className="hidden px-4 py-3 text-left text-sm font-medium text-muted-foreground sm:table-cell">Days</th>
                    <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {requests.data.map((req) => (
                    <tr key={req.public_id} className="border-b last:border-0 hover:bg-muted/30">
                      <td className="px-4 py-3 text-sm font-medium text-foreground">{leaveTypeName(req.leave_type)}</td>
                      <td className="px-4 py-3 text-sm text-muted-foreground">{req.start_date} — {req.end_date}</td>
                      <td className="hidden px-4 py-3 text-sm text-muted-foreground sm:table-cell">{req.days}</td>
                      <td className="px-4 py-3"><StatusBadge status={req.status} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </>
  );
}

function TeamLeaveTab() {
  const [page, setPage] = useState(1);
  const { data, isLoading } = useTeamLeaveRequests({ page });
  const approveLeave = useApproveLeave();
  const rejectLeave = useRejectLeave();

  const requests = data?.data ?? [];

  return (
    <Card>
      <CardHeader><CardTitle>Team Leave Requests</CardTitle></CardHeader>
      <CardContent className="p-0">
        {isLoading ? (
          <div className="space-y-3 p-4">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-12 w-full" />)}</div>
        ) : requests.length === 0 ? (
          <EmptyState icon={CalendarDays} title="No team leave requests" description="Your team members' leave requests will appear here" />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b bg-muted/50">
                  <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">Employee</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">Type</th>
                  <th className="hidden px-4 py-3 text-left text-sm font-medium text-muted-foreground sm:table-cell">Dates</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">Status</th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-muted-foreground">Actions</th>
                </tr>
              </thead>
              <tbody>
                {requests.map((req) => (
                  <tr key={req.public_id} className="border-b last:border-0 hover:bg-muted/30">
                    <td className="px-4 py-3 text-sm font-medium text-foreground">{(req as { employee_name?: string }).employee_name ?? '—'}</td>
                    <td className="px-4 py-3 text-sm text-muted-foreground">{leaveTypeName(req.leave_type)}</td>
                    <td className="hidden px-4 py-3 text-sm text-muted-foreground sm:table-cell">{req.start_date} — {req.end_date}</td>
                    <td className="px-4 py-3"><StatusBadge status={req.status} /></td>
                    <td className="px-4 py-3 text-right">
                      {req.status === 'pending' && (
                        <div className="flex justify-end gap-1">
                          <Button size="sm" variant="ghost" className="h-7 text-green-600" onClick={() => approveLeave.mutate(req.public_id)} disabled={approveLeave.isPending}>
                            <Check className="h-3 w-3" />
                          </Button>
                          <Button size="sm" variant="ghost" className="h-7 text-red-600" onClick={() => rejectLeave.mutate({ publicId: req.public_id, reason: 'Rejected' })} disabled={rejectLeave.isPending}>
                            <X className="h-3 w-3" />
                          </Button>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
