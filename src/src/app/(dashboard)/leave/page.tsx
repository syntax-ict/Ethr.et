'use client';

import { useState } from 'react';
import { CalendarDays, Plus, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Skeleton } from '@/components/ui/skeleton';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
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
import { useLeaveBalance, useMyLeaveRequests, useLeaveTypes, useSubmitLeave, type LeaveBalance } from '@/features/leave/api';
import { toast } from 'sonner';

function leaveTypeName(lt: LeaveBalance['leave_type']): string {
  if (typeof lt === 'string') return lt;
  return lt?.name ?? 'Unknown';
}

export default function LeavePage() {
  const [page, setPage] = useState(1);
  const [dialogOpen, setDialogOpen] = useState(false);
  const { data: balances, isLoading: balancesLoading } = useLeaveBalance();
  const { data: requests, isLoading: requestsLoading } = useMyLeaveRequests({ page });
  const { data: leaveTypes } = useLeaveTypes();
  const submitLeave = useSubmitLeave();

  const [leaveForm, setLeaveForm] = useState({
    leave_type_public_id: '',
    start_date: '',
    end_date: '',
    reason: '',
  });

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
        description="Manage your leave requests and balances"
        actions={
          <Button onClick={() => setDialogOpen(true)}>
            <Plus className="mr-2 h-4 w-4" />
            Apply for Leave
          </Button>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {balancesLoading ? (
          Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-24" />
          ))
        ) : balances && Array.isArray(balances) ? (
          balances.map((b, i) => (
            <Card key={i}>
              <CardContent className="p-4">
                <p className="text-sm text-muted-foreground">{leaveTypeName(b.leave_type)}</p>
                <p className="mt-1 text-2xl font-bold text-foreground">{b.remaining_days}</p>
                <p className="text-xs text-muted-foreground">
                  {b.used_days} used of {b.entitled_days}
                </p>
              </CardContent>
            </Card>
          ))
        ) : null}
      </div>

      <Card>
        <CardHeader>
          <CardTitle>My Leave Requests</CardTitle>
        </CardHeader>
        <CardContent className="p-0">
          {requestsLoading ? (
            <div className="space-y-3 p-4">
              {Array.from({ length: 3 }).map((_, i) => (
                <Skeleton key={i} className="h-12 w-full" />
              ))}
            </div>
          ) : !requests?.data?.length ? (
            <EmptyState
              icon={CalendarDays}
              title="No leave requests"
              description="Apply for leave to see your requests here"
            />
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
                      <td className="px-4 py-3 text-sm text-muted-foreground">
                        {req.start_date} — {req.end_date}
                      </td>
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

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Apply for Leave</DialogTitle>
          </DialogHeader>
          <form onSubmit={handleSubmitLeave} className="space-y-4">
            <div>
              <Label>Leave Type</Label>
              <Select
                value={leaveForm.leave_type_public_id}
                onValueChange={(v) => setLeaveForm((p) => ({ ...p, leave_type_public_id: v }))}
              >
                <SelectTrigger className="mt-1">
                  <SelectValue placeholder="Select leave type" />
                </SelectTrigger>
                <SelectContent>
                  {leaveTypes?.data?.map((lt) => (
                    <SelectItem key={lt.public_id} value={lt.public_id}>
                      {lt.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label htmlFor="start">Start Date</Label>
                <Input
                  id="start"
                  type="date"
                  value={leaveForm.start_date}
                  onChange={(e) => setLeaveForm((p) => ({ ...p, start_date: e.target.value }))}
                  required
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="end">End Date</Label>
                <Input
                  id="end"
                  type="date"
                  value={leaveForm.end_date}
                  onChange={(e) => setLeaveForm((p) => ({ ...p, end_date: e.target.value }))}
                  required
                  className="mt-1"
                />
              </div>
            </div>
            <div>
              <Label htmlFor="reason">Reason</Label>
              <Textarea
                id="reason"
                value={leaveForm.reason}
                onChange={(e) => setLeaveForm((p) => ({ ...p, reason: e.target.value }))}
                placeholder="Optional reason for leave"
                className="mt-1"
              />
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={submitLeave.isPending}>
                {submitLeave.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Submit Request
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
