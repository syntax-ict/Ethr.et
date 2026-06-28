'use client';

import Link from 'next/link';
import {
  Clock,
  CalendarDays,
  Wallet,
  LogIn,
  Plus,
  FileText,
  Users,
  ArrowRight,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Progress } from '@/components/ui/progress';
import { StatusBadge } from '@/components/shared/status-badge';
import { useEmployeeDashboard } from '@/features/dashboard/api';

export default function DashboardPage() {
  const { data, isLoading } = useEmployeeDashboard();

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-64" />
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-32" />
          ))}
        </div>
        <div className="grid gap-4 lg:grid-cols-3">
          <Skeleton className="h-64 lg:col-span-2" />
          <Skeleton className="h-64" />
        </div>
      </div>
    );
  }

  const attendance = data?.attendance_today;
  const balances = data?.leave_balances ?? [];
  const payslip = data?.latest_payslip;
  const holidays = data?.upcoming_holidays ?? [];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-foreground">Dashboard</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Welcome back. Here&apos;s your overview for today.
        </p>
      </div>

      {/* KPI Cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card className="relative overflow-hidden">
          <CardContent className="p-5">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-muted-foreground">Attendance</p>
                <p className="mt-1 text-xl font-bold text-foreground">
                  {attendance?.status === 'checked_in'
                    ? 'Checked In'
                    : attendance?.status === 'checked_out'
                      ? 'Checked Out'
                      : 'Not Checked In'}
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                  {attendance?.check_in ? `Since ${attendance.check_in}` : 'No record today'}
                </p>
              </div>
              <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-100 dark:bg-blue-950">
                <Clock className="h-5 w-5 text-blue-600 dark:text-blue-400" />
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent className="p-5">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-muted-foreground">Leave Balance</p>
                <p className="mt-1 text-xl font-bold text-foreground">
                  {balances.length > 0 ? `${balances[0].remaining} days` : '—'}
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                  {balances.length > 0 ? balances[0].type : 'No leave configured'}
                </p>
              </div>
              <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-green-100 dark:bg-green-950">
                <CalendarDays className="h-5 w-5 text-green-600 dark:text-green-400" />
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent className="p-5">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-muted-foreground">Latest Payslip</p>
                <p className="mt-1 text-xl font-bold text-foreground">
                  {payslip ? formatCurrency(payslip.net_cents) : '—'}
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                  {payslip?.period ?? 'No payslips'}
                </p>
              </div>
              <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-purple-100 dark:bg-purple-950">
                <Wallet className="h-5 w-5 text-purple-600 dark:text-purple-400" />
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent className="p-5">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-muted-foreground">Next Holiday</p>
                <p className="mt-1 text-xl font-bold text-foreground">
                  {holidays.length > 0 ? holidays[0].name : '—'}
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                  {holidays.length > 0 ? holidays[0].date : 'No upcoming'}
                </p>
              </div>
              <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-100 dark:bg-amber-950">
                <CalendarDays className="h-5 w-5 text-amber-600 dark:text-amber-400" />
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        {/* Leave Balances with Progress */}
        <Card className="lg:col-span-2">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-base">Leave Balances</CardTitle>
            <Button variant="ghost" size="sm" className="text-xs" asChild>
              <Link href="/leave">
                View all <ArrowRight className="ml-1 h-3 w-3" />
              </Link>
            </Button>
          </CardHeader>
          <CardContent>
            {balances.length === 0 ? (
              <p className="py-4 text-center text-sm text-muted-foreground">
                No leave balances configured
              </p>
            ) : (
              <div className="space-y-5">
                {balances.map((b, i) => {
                  const pct = b.entitled > 0 ? Math.round((b.used / b.entitled) * 100) : 0;
                  return (
                    <div key={i}>
                      <div className="mb-1.5 flex items-center justify-between">
                        <span className="text-sm font-medium text-foreground">{b.type}</span>
                        <span className="text-sm text-muted-foreground">
                          {b.remaining} of {b.entitled} remaining
                        </span>
                      </div>
                      <Progress value={pct} className="h-2" />
                    </div>
                  );
                })}
              </div>
            )}
          </CardContent>
        </Card>

        {/* Quick Actions */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Quick Actions</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-2">
            <Button variant="outline" className="justify-start" asChild>
              <Link href="/attendance">
                <LogIn className="mr-3 h-4 w-4 text-blue-500" />
                Check In / Out
              </Link>
            </Button>
            <Button variant="outline" className="justify-start" asChild>
              <Link href="/leave">
                <Plus className="mr-3 h-4 w-4 text-green-500" />
                Apply for Leave
              </Link>
            </Button>
            <Button variant="outline" className="justify-start" asChild>
              <Link href="/payroll">
                <FileText className="mr-3 h-4 w-4 text-purple-500" />
                View Payslips
              </Link>
            </Button>
            <Button variant="outline" className="justify-start" asChild>
              <Link href="/employees">
                <Users className="mr-3 h-4 w-4 text-orange-500" />
                Employee Directory
              </Link>
            </Button>
          </CardContent>
        </Card>
      </div>

      {/* Upcoming Holidays */}
      {holidays.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Upcoming Holidays</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid gap-3 sm:grid-cols-3">
              {holidays.map((h, i) => (
                <div
                  key={i}
                  className="flex items-center gap-3 rounded-lg border p-3"
                >
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-100 dark:bg-amber-950">
                    <CalendarDays className="h-5 w-5 text-amber-600 dark:text-amber-400" />
                  </div>
                  <div>
                    <p className="text-sm font-medium text-foreground">{h.name}</p>
                    <p className="text-xs text-muted-foreground">{h.date}</p>
                  </div>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}

function formatCurrency(cents: number): string {
  return (
    new Intl.NumberFormat('en-ET', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(cents / 100) + ' ETB'
  );
}
