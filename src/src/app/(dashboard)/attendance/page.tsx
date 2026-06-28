'use client';

import { useState } from 'react';
import { Clock, LogIn, LogOut } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { EmptyState } from '@/components/shared/empty-state';
import { useAttendanceList, useCheckIn, useCheckOut } from '@/features/attendance/api';
import { toast } from 'sonner';

export default function AttendancePage() {
  const [page, setPage] = useState(1);
  const { data, isLoading } = useAttendanceList({ page, per_page: 25 });
  const checkIn = useCheckIn();
  const checkOut = useCheckOut();

  function handleCheckIn() {
    checkIn.mutate(
      { idempotency_key: crypto.randomUUID(), source: 'web' },
      {
        onSuccess: () => toast.success('Checked in successfully'),
        onError: () => toast.error('Failed to check in'),
      }
    );
  }

  function handleCheckOut() {
    checkOut.mutate(
      { idempotency_key: crypto.randomUUID() },
      {
        onSuccess: () => toast.success('Checked out successfully'),
        onError: () => toast.error('Failed to check out'),
      }
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Attendance"
        description="Track and manage attendance records"
        actions={
          <div className="flex gap-2">
            <Button onClick={handleCheckIn} disabled={checkIn.isPending}>
              <LogIn className="mr-2 h-4 w-4" />
              Check In
            </Button>
            <Button variant="outline" onClick={handleCheckOut} disabled={checkOut.isPending}>
              <LogOut className="mr-2 h-4 w-4" />
              Check Out
            </Button>
          </div>
        }
      />

      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 5 }).map((_, i) => (
            <Skeleton key={i} className="h-14 w-full" />
          ))}
        </div>
      ) : !data?.data?.length ? (
        <EmptyState
          icon={Clock}
          title="No attendance records"
          description="Check in to start recording your attendance"
        />
      ) : (
        <>
          <Card>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b bg-muted/50">
                      <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">Date</th>
                      <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">Check In</th>
                      <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">Check Out</th>
                      <th className="hidden px-4 py-3 text-left text-sm font-medium text-muted-foreground sm:table-cell">Source</th>
                      <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.data.map((record) => (
                      <tr key={record.public_id} className="border-b last:border-0 hover:bg-muted/30">
                        <td className="px-4 py-3 text-sm font-medium text-foreground">{record.date}</td>
                        <td className="px-4 py-3 text-sm text-muted-foreground">{record.check_in ?? '—'}</td>
                        <td className="px-4 py-3 text-sm text-muted-foreground">{record.check_out ?? '—'}</td>
                        <td className="hidden px-4 py-3 text-sm capitalize text-muted-foreground sm:table-cell">{record.source}</td>
                        <td className="px-4 py-3"><StatusBadge status={record.status} /></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>

          {data.meta && data.meta.last_page > 1 && (
            <div className="flex items-center justify-between">
              <p className="text-sm text-muted-foreground">
                Showing {data.meta.from}–{data.meta.to} of {data.meta.total}
              </p>
              <div className="flex gap-2">
                <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
                <Button variant="outline" size="sm" disabled={page >= data.meta.last_page} onClick={() => setPage(page + 1)}>Next</Button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
