'use client';

import { useState } from 'react';
import { Clock, Plus, Loader2, Users, Building2, GitBranch, CalendarDays, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter,
} from '@/components/ui/dialog';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
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
  is_default: boolean;
  is_active: boolean;
  assignments_count?: number;
}

interface ShiftAssignment {
  shift: Shift | null;
  assignable_type: string;
  effective_from: string | null;
  effective_to: string | null;
  created_at: string;
}

export default function ShiftsPage() {
  const queryClient = useQueryClient();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [assignOpen, setAssignOpen] = useState(false);
  const [form, setForm] = useState({
    name: '', start_time: '', end_time: '', grace_minutes: '15',
  });
  const [assignForm, setAssignForm] = useState({
    shift_public_id: '',
    assignable_type: 'employee' as 'employee' | 'department' | 'branch',
    assignable_public_id: '',
    effective_from: new Date().toISOString().split('T')[0],
    effective_to: '',
  });

  const { data, isLoading } = useQuery<{ data: Shift[] }>({
    queryKey: ['shifts'],
    queryFn: async () => (await apiClient.get('/shifts')).data,
  });

  const { data: scheduleData, isLoading: scheduleLoading } = useQuery<{ data: ShiftAssignment[] }>({
    queryKey: ['shifts', 'schedule'],
    queryFn: async () => (await apiClient.get('/shifts/schedule')).data,
  });

  const { data: employees } = useQuery<{ data: { public_id: string; name: string; employee_code: string }[] }>({
    queryKey: ['employees', 'lookup'],
    queryFn: async () => (await apiClient.get('/employees', { params: { per_page: 200 } })).data,
    enabled: assignOpen,
  });

  const { data: departments } = useQuery<{ data: { public_id: string; name: string }[] }>({
    queryKey: ['departments', 'lookup'],
    queryFn: async () => (await apiClient.get('/departments')).data,
    enabled: assignOpen && assignForm.assignable_type === 'department',
  });

  const { data: branches } = useQuery<{ data: { public_id: string; name: string }[] }>({
    queryKey: ['branches', 'lookup'],
    queryFn: async () => (await apiClient.get('/branches')).data,
    enabled: assignOpen && assignForm.assignable_type === 'branch',
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
      const e = err as { response?: { data?: { detail?: string } } };
      toast.error(e.response?.data?.detail || 'Failed to create shift');
    },
  });

  const deleteShift = useMutation({
    mutationFn: async (publicId: string) => {
      await apiClient.delete(`/shifts/${publicId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['shifts'] });
      toast.success('Shift deleted');
    },
    onError: (err: unknown) => {
      const e = err as { response?: { data?: { detail?: string } } };
      toast.error(e.response?.data?.detail || 'Failed to delete shift');
    },
  });

  const assignShift = useMutation({
    mutationFn: async (payload: typeof assignForm) => {
      const { data } = await apiClient.post('/shifts/assign', {
        ...payload,
        effective_to: payload.effective_to || null,
      });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['shifts'] });
      toast.success('Shift assigned');
      setAssignOpen(false);
      setAssignForm({ shift_public_id: '', assignable_type: 'employee', assignable_public_id: '', effective_from: new Date().toISOString().split('T')[0], effective_to: '' });
    },
    onError: (err: unknown) => {
      const e = err as { response?: { data?: { detail?: string } } };
      toast.error(e.response?.data?.detail || 'Failed to assign shift');
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

  function handleAssign(e: React.FormEvent) {
    e.preventDefault();
    assignShift.mutate(assignForm);
  }

  function openAssignFor(shiftPublicId: string) {
    setAssignForm((p) => ({ ...p, shift_public_id: shiftPublicId }));
    setAssignOpen(true);
  }

  const shifts = data?.data ?? [];
  const schedule = scheduleData?.data ?? [];

  const assignableOptions = assignForm.assignable_type === 'employee'
    ? (employees?.data ?? []).map((e) => ({ value: e.public_id, label: `${e.name} (${e.employee_code})` }))
    : assignForm.assignable_type === 'department'
      ? (departments?.data ?? []).map((d) => ({ value: d.public_id, label: d.name }))
      : (branches?.data ?? []).map((b) => ({ value: b.public_id, label: b.name }));

  const typeIcon = { employee: Users, department: Building2, branch: GitBranch };

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title="Shifts"
          description="Manage work shift schedules and assign shifts to employees, departments, or branches"
          actions={
            <div className="flex gap-2">
              <Button variant="outline" onClick={() => setAssignOpen(true)}>
                <CalendarDays className="mr-2 h-4 w-4" /> Assign Shift
              </Button>
              <Button onClick={() => setDialogOpen(true)}>
                <Plus className="mr-2 h-4 w-4" /> Add Shift
              </Button>
            </div>
          }
        />

        <Tabs defaultValue="shifts">
          <TabsList>
            <TabsTrigger value="shifts">Shifts</TabsTrigger>
            <TabsTrigger value="schedule">Schedule</TabsTrigger>
          </TabsList>

          <TabsContent value="shifts" className="mt-4">
            {isLoading ? (
              <div className="space-y-3">
                {Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-12 w-full" />)}
              </div>
            ) : shifts.length === 0 ? (
              <EmptyState icon={Clock} title="No shifts configured" description="Add shifts to define work schedules" />
            ) : (
              <Card>
                <CardContent className="p-0">
                  <div className="overflow-x-auto">
                    <table className="w-full">
                      <thead>
                        <tr className="border-b bg-muted/50">
                          <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">Name</th>
                          <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">Start</th>
                          <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">End</th>
                          <th className="px-4 py-3 text-right text-xs font-medium uppercase text-muted-foreground">Grace</th>
                          <th className="hidden px-4 py-3 text-right text-xs font-medium uppercase text-muted-foreground sm:table-cell">Assignments</th>
                          <th className="px-4 py-3 text-right text-xs font-medium uppercase text-muted-foreground">Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        {shifts.map((shift) => (
                          <tr key={shift.public_id} className="border-b last:border-0 hover:bg-muted/30">
                            <td className="px-4 py-3 text-sm font-medium text-foreground">
                              {shift.name}
                              {shift.is_default && <Badge variant="outline" className="ml-2 text-xs">Default</Badge>}
                            </td>
                            <td className="px-4 py-3 text-sm text-muted-foreground">{shift.start_time}</td>
                            <td className="px-4 py-3 text-sm text-muted-foreground">{shift.end_time}</td>
                            <td className="px-4 py-3 text-right text-sm text-muted-foreground">{shift.grace_minutes}m</td>
                            <td className="hidden px-4 py-3 text-right text-sm text-muted-foreground sm:table-cell">
                              {shift.assignments_count ?? '—'}
                            </td>
                            <td className="px-4 py-3 text-right">
                              <div className="flex justify-end gap-1">
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => openAssignFor(shift.public_id)}
                                >
                                  <CalendarDays className="h-3 w-3 mr-1" /> Assign
                                </Button>
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  className="text-destructive hover:text-destructive"
                                  onClick={() => {
                                    if (confirm('Delete this shift?')) deleteShift.mutate(shift.public_id);
                                  }}
                                >
                                  <Trash2 className="h-3 w-3" />
                                </Button>
                              </div>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </CardContent>
              </Card>
            )}
          </TabsContent>

          <TabsContent value="schedule" className="mt-4">
            {scheduleLoading ? (
              <div className="space-y-3">
                {Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-12 w-full" />)}
              </div>
            ) : schedule.length === 0 ? (
              <EmptyState
                icon={CalendarDays}
                title="No shift assignments"
                description="Assign shifts to employees, departments, or branches to populate the schedule"
              />
            ) : (
              <Card>
                <CardHeader>
                  <CardTitle className="text-base">Active Schedule</CardTitle>
                </CardHeader>
                <CardContent className="p-0">
                  <div className="overflow-x-auto">
                    <table className="w-full">
                      <thead>
                        <tr className="border-b bg-muted/50">
                          <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">Shift</th>
                          <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">Assigned To</th>
                          <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">From</th>
                          <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">To</th>
                        </tr>
                      </thead>
                      <tbody>
                        {schedule.map((assignment, i) => {
                          const TypeIcon = typeIcon[assignment.assignable_type.toLowerCase() as keyof typeof typeIcon] ?? Users;
                          return (
                            <tr key={i} className="border-b last:border-0 hover:bg-muted/30">
                              <td className="px-4 py-3 text-sm font-medium text-foreground">
                                {assignment.shift?.name ?? '—'}
                                <span className="ml-2 text-xs text-muted-foreground">
                                  {assignment.shift?.start_time}–{assignment.shift?.end_time}
                                </span>
                              </td>
                              <td className="px-4 py-3 text-sm text-muted-foreground">
                                <div className="flex items-center gap-2">
                                  <TypeIcon className="h-3.5 w-3.5" />
                                  <Badge variant="outline" className="text-xs capitalize">
                                    {assignment.assignable_type}
                                  </Badge>
                                </div>
                              </td>
                              <td className="px-4 py-3 text-sm text-muted-foreground">
                                {assignment.effective_from ?? '—'}
                              </td>
                              <td className="px-4 py-3 text-sm text-muted-foreground">
                                {assignment.effective_to ?? <span className="text-xs italic">Ongoing</span>}
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                </CardContent>
              </Card>
            )}
          </TabsContent>
        </Tabs>

        {/* Create shift dialog */}
        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
          <DialogContent>
            <DialogHeader><DialogTitle>Add Shift</DialogTitle></DialogHeader>
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
                <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>Cancel</Button>
                <Button type="submit" disabled={createShift.isPending}>
                  {createShift.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                  Add Shift
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>

        {/* Assign shift dialog */}
        <Dialog open={assignOpen} onOpenChange={setAssignOpen}>
          <DialogContent>
            <DialogHeader><DialogTitle>Assign Shift</DialogTitle></DialogHeader>
            <form onSubmit={handleAssign} className="space-y-4">
              <div>
                <Label>Shift</Label>
                <Select
                  value={assignForm.shift_public_id}
                  onValueChange={(v) => setAssignForm((p) => ({ ...p, shift_public_id: v }))}
                >
                  <SelectTrigger className="mt-1"><SelectValue placeholder="Select shift" /></SelectTrigger>
                  <SelectContent>
                    {shifts.map((s) => (
                      <SelectItem key={s.public_id} value={s.public_id}>
                        {s.name} ({s.start_time}–{s.end_time})
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div>
                <Label>Assign To</Label>
                <Select
                  value={assignForm.assignable_type}
                  onValueChange={(v) => setAssignForm((p) => ({
                    ...p,
                    assignable_type: v as 'employee' | 'department' | 'branch',
                    assignable_public_id: '',
                  }))}
                >
                  <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="employee">Employee</SelectItem>
                    <SelectItem value="department">Department</SelectItem>
                    <SelectItem value="branch">Branch</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div>
                <Label>
                  {assignForm.assignable_type === 'employee' ? 'Employee'
                    : assignForm.assignable_type === 'department' ? 'Department' : 'Branch'}
                </Label>
                <Select
                  value={assignForm.assignable_public_id}
                  onValueChange={(v) => setAssignForm((p) => ({ ...p, assignable_public_id: v }))}
                >
                  <SelectTrigger className="mt-1"><SelectValue placeholder="Select…" /></SelectTrigger>
                  <SelectContent>
                    {assignableOptions.map((opt) => (
                      <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>Effective From</Label>
                  <Input
                    type="date"
                    value={assignForm.effective_from}
                    onChange={(e) => setAssignForm((p) => ({ ...p, effective_from: e.target.value }))}
                    required
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label>Effective To <span className="text-xs text-muted-foreground">(optional)</span></Label>
                  <Input
                    type="date"
                    value={assignForm.effective_to}
                    onChange={(e) => setAssignForm((p) => ({ ...p, effective_to: e.target.value }))}
                    className="mt-1"
                  />
                </div>
              </div>

              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setAssignOpen(false)}>Cancel</Button>
                <Button
                  type="submit"
                  disabled={assignShift.isPending || !assignForm.shift_public_id || !assignForm.assignable_public_id}
                >
                  {assignShift.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                  Assign
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
