'use client';

import Link from 'next/link';
import {
  Clock, CalendarDays, Wallet, LogIn, Plus, FileText, Users,
  ArrowRight, CheckSquare, TrendingUp, UserCheck, UserX,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Progress } from '@/components/ui/progress';
import { useEmployeeDashboard, useManagerDashboard } from '@/features/dashboard/api';
import { usePermissions } from '@/lib/hooks/usePermissions';

export default function DashboardPage() {
  const { role, isSupervisor, can } = usePermissions();

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-foreground">Dashboard</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          {can.viewExecutiveDashboard
            ? 'Executive overview of your organization'
            : isSupervisor
              ? 'Your team at a glance'
              : 'Your personal overview'}
        </p>
      </div>

      <EmployeeSelfServiceCards />
      {isSupervisor && <ManagerCards />}
      <QuickActions />
    </div>
  );
}

function EmployeeSelfServiceCards() {
  const { data, isLoading } = useEmployeeDashboard();

  if (isLoading) {
    return (
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-32" />)}
      </div>
    );
  }

  const attendance = data?.attendance_today;
  const balances = data?.leave_balances ?? [];
  const payslip = data?.latest_payslip;
  const holidays = data?.upcoming_holidays ?? [];

  return (
    <>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <KpiCard
          icon={Clock}
          iconBg="bg-blue-100 dark:bg-blue-950"
          iconColor="text-blue-600 dark:text-blue-400"
          title="Attendance"
          value={attendance?.status === 'checked_in' ? 'Checked In' : attendance?.status === 'checked_out' ? 'Checked Out' : 'Not Checked In'}
          sub={attendance?.check_in ? `Since ${attendance.check_in}` : 'No record today'}
        />
        <KpiCard
          icon={CalendarDays}
          iconBg="bg-green-100 dark:bg-green-950"
          iconColor="text-green-600 dark:text-green-400"
          title="Leave Balance"
          value={balances.length > 0 ? `${balances[0].remaining} days` : '—'}
          sub={balances.length > 0 ? String(balances[0].type) : 'No leave configured'}
        />
        <KpiCard
          icon={Wallet}
          iconBg="bg-purple-100 dark:bg-purple-950"
          iconColor="text-purple-600 dark:text-purple-400"
          title="Latest Payslip"
          value={payslip ? formatETB(payslip.net_cents) : '—'}
          sub={payslip?.period ?? 'No payslips'}
        />
        <KpiCard
          icon={CalendarDays}
          iconBg="bg-amber-100 dark:bg-amber-950"
          iconColor="text-amber-600 dark:text-amber-400"
          title="Next Holiday"
          value={holidays.length > 0 ? holidays[0].name : '—'}
          sub={holidays.length > 0 ? holidays[0].date : 'No upcoming'}
        />
      </div>

      {balances.length > 0 && (
        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-base">Leave Balances</CardTitle>
            <Button variant="ghost" size="sm" className="text-xs" asChild>
              <Link href="/leave">View all <ArrowRight className="ml-1 h-3 w-3" /></Link>
            </Button>
          </CardHeader>
          <CardContent>
            <div className="space-y-5">
              {balances.map((b, i) => {
                const pct = b.entitled > 0 ? Math.round((b.used / b.entitled) * 100) : 0;
                return (
                  <div key={i}>
                    <div className="mb-1.5 flex items-center justify-between">
                      <span className="text-sm font-medium text-foreground">{String(b.type)}</span>
                      <span className="text-sm text-muted-foreground">{b.remaining} of {b.entitled} remaining</span>
                    </div>
                    <Progress value={pct} className="h-2" />
                  </div>
                );
              })}
            </div>
          </CardContent>
        </Card>
      )}
    </>
  );
}

function ManagerCards() {
  const { data, isLoading } = useManagerDashboard();

  if (isLoading) {
    return (
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-32" />)}
      </div>
    );
  }

  if (!data) return null;

  const attn = data.team_attendance;

  return (
    <>
      <div>
        <h2 className="text-lg font-semibold text-foreground">Team Overview</h2>
        <p className="text-sm text-muted-foreground">Your direct reports</p>
      </div>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <KpiCard
          icon={Users}
          iconBg="bg-indigo-100 dark:bg-indigo-950"
          iconColor="text-indigo-600 dark:text-indigo-400"
          title="Team Size"
          value={String(data.team_size)}
          sub="Direct reports"
        />
        <KpiCard
          icon={UserCheck}
          iconBg="bg-green-100 dark:bg-green-950"
          iconColor="text-green-600 dark:text-green-400"
          title="Present Today"
          value={String(attn.present)}
          sub={`${attn.late} late`}
        />
        <KpiCard
          icon={UserX}
          iconBg="bg-red-100 dark:bg-red-950"
          iconColor="text-red-600 dark:text-red-400"
          title="Absent Today"
          value={String(attn.absent)}
          sub="Not checked in"
        />
        <KpiCard
          icon={CheckSquare}
          iconBg="bg-orange-100 dark:bg-orange-950"
          iconColor="text-orange-600 dark:text-orange-400"
          title="Pending Approvals"
          value={String(data.pending_approvals.total)}
          sub={`${data.pending_approvals.leave} leave requests`}
        />
      </div>

      {data.team_on_leave.length > 0 && (
        <Card>
          <CardHeader><CardTitle className="text-base">On Leave This Week</CardTitle></CardHeader>
          <CardContent>
            <div className="space-y-2">
              {data.team_on_leave.map((t, i) => (
                <div key={i} className="flex items-center justify-between rounded-lg border p-3">
                  <span className="text-sm font-medium">{t.employee_name}</span>
                  <span className="text-xs text-muted-foreground">{t.start_date} — {t.end_date}</span>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      )}
    </>
  );
}

function QuickActions() {
  const { can, isSupervisor } = usePermissions();

  const actions = [
    { label: 'Check In / Out', href: '/attendance', icon: LogIn, color: 'text-blue-500', show: true },
    { label: 'Apply for Leave', href: '/leave', icon: Plus, color: 'text-green-500', show: true },
    { label: 'View Payslips', href: '/payroll/payslips', icon: FileText, color: 'text-purple-500', show: true },
    { label: 'Pending Approvals', href: '/approvals', icon: CheckSquare, color: 'text-orange-500', show: isSupervisor },
    { label: 'Manage Employees', href: '/employees', icon: Users, color: 'text-indigo-500', show: can.manageEmployees },
    { label: 'Run Payroll', href: '/payroll', icon: Wallet, color: 'text-pink-500', show: can.processPayroll },
    { label: 'View Reports', href: '/reports', icon: TrendingUp, color: 'text-teal-500', show: can.viewReports },
  ].filter((a) => a.show);

  return (
    <Card>
      <CardHeader><CardTitle className="text-base">Quick Actions</CardTitle></CardHeader>
      <CardContent className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {actions.map((a) => (
          <Button key={a.href} variant="outline" className="justify-start" asChild>
            <Link href={a.href}>
              <a.icon className={`mr-3 h-4 w-4 ${a.color}`} />
              {a.label}
            </Link>
          </Button>
        ))}
      </CardContent>
    </Card>
  );
}

function KpiCard({ icon: Icon, iconBg, iconColor, title, value, sub }: {
  icon: React.ComponentType<{ className?: string }>;
  iconBg: string; iconColor: string; title: string; value: string; sub: string;
}) {
  return (
    <Card>
      <CardContent className="p-5">
        <div className="flex items-center justify-between">
          <div>
            <p className="text-sm text-muted-foreground">{title}</p>
            <p className="mt-1 text-xl font-bold text-foreground">{value}</p>
            <p className="mt-0.5 text-xs text-muted-foreground">{sub}</p>
          </div>
          <div className={`flex h-11 w-11 items-center justify-center rounded-xl ${iconBg}`}>
            <Icon className={`h-5 w-5 ${iconColor}`} />
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

function formatETB(cents: number): string {
  return new Intl.NumberFormat('en-ET', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(cents / 100) + ' ETB';
}
