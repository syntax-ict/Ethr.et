"use client";

import Link from "next/link";
import {
  Clock,
  CalendarDays,
  Wallet,
  LogIn,
  Plus,
  FileText,
  Users,
  ArrowRight,
  CheckSquare,
  TrendingUp,
  UserCheck,
  UserX,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Progress } from "@/components/ui/progress";
import {
  useEmployeeDashboard,
  useManagerDashboard,
} from "@/features/dashboard/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";

export default function DashboardPage() {
  const { t } = useT();
  const { role, isSupervisor, can } = usePermissions();

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-foreground">
          {t("dashboard.title", "Dashboard")}
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">
          {can.viewExecutiveDashboard
            ? t(
                "dashboard.executive_overview",
                "Executive overview of your organization",
              )
            : isSupervisor
              ? t("dashboard.team_glance", "Your team at a glance")
              : t("dashboard.personal_overview", "Your personal overview")}
        </p>
      </div>

      <EmployeeSelfServiceCards />
      {isSupervisor && <ManagerCards />}
      <QuickActions />
    </div>
  );
}

function EmployeeSelfServiceCards() {
  const { t } = useT();
  const { data, isLoading } = useEmployeeDashboard();

  if (isLoading) {
    return (
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-32" />
        ))}
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
          tone="info"
          title={t("dashboard.attendance", "Attendance")}
          value={
            attendance?.status === "checked_in"
              ? t("dashboard.checked_in", "Checked In")
              : attendance?.status === "checked_out"
                ? t("dashboard.checked_out", "Checked Out")
                : t("dashboard.not_checked_in", "Not Checked In")
          }
          sub={
            attendance?.check_in
              ? t("dashboard.since", `Since ${attendance.check_in}`)
              : t("dashboard.no_record_today", "No record today")
          }
        />
        <KpiCard
          icon={CalendarDays}
          tone="success"
          title={t("dashboard.leave_balance", "Leave Balance")}
          value={balances.length > 0 ? `${balances[0].remaining} days` : "—"}
          sub={
            balances.length > 0
              ? String(balances[0].type)
              : t("dashboard.no_leave_configured", "No leave configured")
          }
        />
        <KpiCard
          icon={Wallet}
          tone="primary"
          title={t("dashboard.latest_payslip", "Latest Payslip")}
          value={payslip ? formatETB(payslip.net_cents) : "—"}
          sub={payslip?.period ?? t("dashboard.no_payslips", "No payslips")}
        />
        <KpiCard
          icon={CalendarDays}
          tone="warning"
          title={t("dashboard.next_holiday", "Next Holiday")}
          value={holidays.length > 0 ? holidays[0].name : "—"}
          sub={
            holidays.length > 0
              ? holidays[0].date
              : t("dashboard.no_upcoming", "No upcoming")
          }
        />
      </div>

      {balances.length > 0 && (
        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-base">
              {t("dashboard.leave_balances", "Leave Balances")}
            </CardTitle>
            <Button variant="ghost" size="sm" className="text-xs" asChild>
              <Link href="/leave">
                {t("common.view_all", "View all")}{" "}
                <ArrowRight className="ml-1 h-3 w-3" />
              </Link>
            </Button>
          </CardHeader>
          <CardContent>
            <div className="space-y-5">
              {balances.map((b, i) => {
                const pct =
                  b.entitled > 0 ? Math.round((b.used / b.entitled) * 100) : 0;
                return (
                  <div key={i}>
                    <div className="mb-1.5 flex items-center justify-between">
                      <span className="text-sm font-medium text-foreground">
                        {String(b.type)}
                      </span>
                      <span className="text-sm text-muted-foreground">
                        {t(
                          "dashboard.remaining_of",
                          `${b.remaining} of ${b.entitled} remaining`,
                        )}
                      </span>
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
  const { t } = useT();
  const { data, isLoading } = useManagerDashboard();

  if (isLoading) {
    return (
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-32" />
        ))}
      </div>
    );
  }

  if (!data) return null;

  const attn = data.team_attendance;

  return (
    <>
      <div>
        <h2 className="text-lg font-semibold text-foreground">
          {t("dashboard.team_overview", "Team Overview")}
        </h2>
        <p className="text-sm text-muted-foreground">
          {t("dashboard.your_direct_reports", "Your direct reports")}
        </p>
      </div>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <KpiCard
          icon={Users}
          tone="primary"
          title={t("dashboard.team_size", "Team Size")}
          value={String(data.team_size)}
          sub={t("dashboard.direct_reports", "Direct reports")}
        />
        <KpiCard
          icon={UserCheck}
          tone="success"
          title={t("dashboard.present_today", "Present Today")}
          value={String(attn.present)}
          sub={t("dashboard.late_count", `${attn.late} late`)}
        />
        <KpiCard
          icon={UserX}
          tone="error"
          title={t("dashboard.absent_today", "Absent Today")}
          value={String(attn.absent)}
          sub={t("dashboard.not_checked_in", "Not checked in")}
        />
        <KpiCard
          icon={CheckSquare}
          tone="warning"
          title={t("dashboard.pending_approvals", "Pending Approvals")}
          value={String(data.pending_approvals.total)}
          sub={t(
            "dashboard.leave_requests_count",
            `${data.pending_approvals.leave} leave requests`,
          )}
        />
      </div>

      {data.team_on_leave.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t("dashboard.on_leave_this_week", "On Leave This Week")}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-2">
              {data.team_on_leave.map((member, i) => (
                <div
                  key={i}
                  className="flex items-center justify-between rounded-lg border p-3"
                >
                  <span className="text-sm font-medium">
                    {member.employee_name}
                  </span>
                  <span className="text-xs text-muted-foreground">
                    {member.start_date} — {member.end_date}
                  </span>
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
  const { t } = useT();
  const { can, isSupervisor } = usePermissions();

  const actions = [
    {
      label:
        t("common.check_in", "Check In") +
        " / " +
        t("common.check_out", "Check Out"),
      href: "/attendance",
      icon: LogIn,
      color: "text-status-info",
      show: true,
    },
    {
      label: t("common.apply_leave", "Apply for Leave"),
      href: "/leave",
      icon: Plus,
      color: "text-status-success",
      show: true,
    },
    {
      label: t("dashboard.view_payslips", "View Payslips"),
      href: "/payroll/payslips",
      icon: FileText,
      color: "text-interactive-primary",
      show: true,
    },
    {
      label: t("dashboard.pending_approvals", "Pending Approvals"),
      href: "/approvals",
      icon: CheckSquare,
      color: "text-status-warning",
      show: isSupervisor,
    },
    {
      label: t("dashboard.manage_employees", "Manage Employees"),
      href: "/employees",
      icon: Users,
      color: "text-interactive-primary",
      show: can.manageEmployees,
    },
    {
      label: t("command.run_payroll", "Run Payroll"),
      href: "/payroll",
      icon: Wallet,
      color: "text-brand-accent",
      show: can.processPayroll,
    },
    {
      label: t("dashboard.view_reports", "View Reports"),
      href: "/reports",
      icon: TrendingUp,
      color: "text-status-info",
      show: can.viewReports,
    },
  ].filter((a) => a.show);

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">
          {t("dashboard.quick_actions", "Quick Actions")}
        </CardTitle>
      </CardHeader>
      <CardContent className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {actions.map((a) => (
          <Button
            key={a.href}
            variant="outline"
            className="justify-start"
            asChild
          >
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

type KpiTone = "info" | "success" | "warning" | "error" | "primary";

const toneBg: Record<KpiTone, string> = {
  info: "bg-status-info/10",
  success: "bg-status-success/10",
  warning: "bg-status-warning/10",
  error: "bg-status-error/10",
  primary: "bg-interactive-primary/10",
};

const toneText: Record<KpiTone, string> = {
  info: "text-status-info",
  success: "text-status-success",
  warning: "text-status-warning",
  error: "text-status-error",
  primary: "text-interactive-primary",
};

function KpiCard({
  icon: Icon,
  tone,
  title,
  value,
  sub,
}: {
  icon: React.ComponentType<{ className?: string }>;
  tone: KpiTone;
  title: string;
  value: string;
  sub: string;
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
          <div
            className={`flex h-11 w-11 items-center justify-center rounded-xl ${toneBg[tone]}`}
          >
            <Icon className={`h-5 w-5 ${toneText[tone]}`} />
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

function formatETB(cents: number): string {
  return (
    new Intl.NumberFormat("en-ET", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(cents / 100) + " ETB"
  );
}
