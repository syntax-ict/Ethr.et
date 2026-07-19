"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  LayoutDashboard,
  Users,
  Clock,
  CalendarDays,
  Wallet,
  BarChart3,
  Settings,
  Building2,
  Bell,
  Receipt,
  FilePenLine,
  Shield,
  CheckSquare,
  Megaphone,
  Contact,
  Banknote,
  Calendar,
  Timer,
  ListChecks,
  TrendingUp,
  Fingerprint,
  KeyRound,
  Webhook,
  UserCircle,
  UsersRound,
  ShieldCheck,
  BellRing,
  ScrollText,
  CreditCard,
  Activity,
  QrCode,
  Smartphone,
  BookOpen,
  Monitor,
  Settings2,
  CalendarRange,
  Mail,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";

interface NavItem {
  label: string;
  href: string;
  icon: React.ComponentType<{ className?: string }>;
  show: boolean;
}

interface NavSection {
  title?: string;
  items: NavItem[];
}

interface SidebarNavProps {
  onNavigate?: () => void;
  collapsed?: boolean;
}

export function SidebarNav({ onNavigate, collapsed = false }: SidebarNavProps) {
  const pathname = usePathname();
  const { can, isSupervisor, isFinanceAdmin, isTenantAdmin } = usePermissions();
  const { t } = useT();

  const sections: NavSection[] = [
    {
      items: [
        {
          label: t("nav.dashboard", "Dashboard"),
          href: "/dashboard",
          icon: LayoutDashboard,
          show: true,
        },
        {
          label: t("nav.my_profile", "My Profile"),
          href: "/profile",
          icon: UserCircle,
          show: true,
        },
        {
          label: t("nav.security", "Security"),
          href: "/profile/security",
          icon: ShieldCheck,
          show: true,
        },
        {
          label: t("nav.directory", "Directory"),
          href: "/directory",
          icon: Contact,
          show: true,
        },
        {
          label: t("nav.announcements", "Announcements"),
          href: "/announcements",
          icon: Megaphone,
          show: true,
        },
      ],
    },
    {
      title: t("nav.section.hr", "HR"),
      items: [
        {
          label: t("nav.employees", "Employees"),
          href: "/employees",
          icon: Users,
          show: can.manageEmployees,
        },
        {
          label: t("nav.organization", "Organization"),
          href: "/organization",
          icon: Building2,
          show: can.manageOrg,
        },
      ],
    },
    {
      title: t("nav.section.operations", "Operations"),
      items: [
        {
          label: t("nav.attendance", "Attendance"),
          href: "/attendance",
          icon: Clock,
          show: true,
        },
        {
          label: t("nav.mobile_checkin", "Mobile Check-in"),
          href: "/attendance/mobile",
          icon: Smartphone,
          show: true,
        },
        {
          label: t("nav.scan_qr", "Scan QR"),
          href: "/attendance/scan",
          icon: QrCode,
          show: true,
        },
        {
          label: t("nav.team_attendance", "Team Attendance"),
          href: "/attendance/team",
          icon: UsersRound,
          show: isSupervisor,
        },
        {
          label: t("nav.corrections", "Corrections"),
          href: "/attendance/corrections",
          icon: FilePenLine,
          show: true,
        },
        {
          label: t("nav.intelligence", "Intelligence"),
          href: "/attendance/intelligence",
          icon: Activity,
          show: can.manageEmployees,
        },
        {
          label: t("nav.overtime", "Overtime"),
          href: "/attendance/overtime",
          icon: TrendingUp,
          show: can.manageEmployees,
        },
        {
          label: t("nav.shifts", "Shifts"),
          href: "/shifts",
          icon: CalendarRange,
          show: can.manageEmployees,
        },
        {
          label: t("nav.qr_generator", "QR Generator"),
          href: "/attendance/qr",
          icon: QrCode,
          show: can.manageEmployees,
        },
        {
          label: t("nav.kiosks", "Kiosks"),
          href: "/attendance/kiosks",
          icon: Monitor,
          show: can.manageEmployees,
        },
        {
          label: t("nav.attendance_settings", "Attendance Settings"),
          href: "/attendance/settings",
          icon: Settings2,
          show: can.manageEmployees,
        },
        {
          label: t("nav.leave", "Leave"),
          href: "/leave",
          icon: CalendarDays,
          show: true,
        },
        {
          label: t("nav.approvals", "Approvals"),
          href: "/approvals",
          icon: CheckSquare,
          show: isSupervisor,
        },
        {
          label: t("nav.devices", "Devices"),
          href: "/devices",
          icon: Fingerprint,
          show: can.manageEmployees,
        },
      ],
    },
    {
      title: t("nav.section.finance", "Finance"),
      items: [
        {
          label: t("nav.payroll", "Payroll Runs"),
          href: "/payroll",
          icon: Wallet,
          show: can.viewPayrollRuns,
        },
        {
          label: t("nav.loans", "Loans"),
          href: "/payroll/loans",
          icon: Banknote,
          show: isFinanceAdmin,
        },
        {
          label: t("nav.payslips", "My Payslips"),
          href: "/payroll/payslips",
          icon: Receipt,
          show: true,
        },
        {
          label: t("nav.billing", "Billing"),
          href: "/billing",
          icon: CreditCard,
          show: isTenantAdmin,
        },
      ],
    },
    {
      title: t("nav.section.insights", "Insights"),
      items: [
        {
          label: t("nav.reports", "Reports"),
          href: "/reports",
          icon: BarChart3,
          show: can.viewReports,
        },
        {
          label: t("nav.analytics", "Analytics"),
          href: "/analytics",
          icon: TrendingUp,
          show: isTenantAdmin,
        },
      ],
    },
    {
      title: t("nav.section.configuration", "Configuration"),
      items: [
        {
          label: t("nav.holidays", "Holidays"),
          href: "/settings/holidays",
          icon: Calendar,
          show: can.manageEmployees,
        },
        {
          label: t("nav.leave_types", "Leave Types"),
          href: "/settings/leave-types",
          icon: ListChecks,
          show: can.manageEmployees,
        },
        {
          label: t("nav.shift_config", "Shifts"),
          href: "/settings/shifts",
          icon: Timer,
          show: can.manageEmployees,
        },
        {
          label: t("nav.roles", "Roles"),
          href: "/settings/roles",
          icon: ShieldCheck,
          show: isTenantAdmin,
        },
        {
          label: t("nav.settings", "Settings"),
          href: "/settings",
          icon: Settings,
          show: can.manageSettings,
        },
      ],
    },
    {
      title: t("nav.section.integrations", "Integrations"),
      items: [
        {
          label: t("nav.api_keys", "API Keys"),
          href: "/settings/api-keys",
          icon: KeyRound,
          show: isTenantAdmin,
        },
        {
          label: t("nav.webhooks", "Webhooks"),
          href: "/settings/webhooks",
          icon: Webhook,
          show: isTenantAdmin,
        },
        {
          label: t("nav.accounting", "Accounting"),
          href: "/settings/accounting",
          icon: BookOpen,
          show: can.manageSettings,
        },
        {
          label: t("nav.notification_templates", "Notification Templates"),
          href: "/settings/notification-templates",
          icon: Mail,
          show: isTenantAdmin,
        },
        {
          label: t("nav.audit_log", "Audit Log"),
          href: "/settings/audit-logs",
          icon: ScrollText,
          show: isTenantAdmin,
        },
      ],
    },
    {
      title: t("nav.section.system", "System"),
      items: [
        {
          label: t("nav.notifications", "Notifications"),
          href: "/notifications",
          icon: Bell,
          show: true,
        },
        {
          label: t("nav.preferences", "Preferences"),
          href: "/notifications/preferences",
          icon: BellRing,
          show: true,
        },
        {
          label: t("nav.admin", "Admin Console"),
          href: "/admin",
          icon: Shield,
          show: can.viewAdminConsole,
        },
        {
          label: t("nav.tenants", "Tenants"),
          href: "/admin/tenants",
          icon: Building2,
          show: can.viewAdminConsole,
        },
      ],
    },
  ];

  const visibleSections = sections
    .map((s) => ({ ...s, items: s.items.filter((i) => i.show) }))
    .filter((s) => s.items.length > 0);

  return (
    <nav
      aria-label="Main navigation"
      className={cn("flex flex-col gap-0.5", collapsed ? "px-2" : "px-3 py-2")}
    >
      {visibleSections.map((section, si) => (
        <div key={si} className="flex flex-col gap-0.5">
          {section.title &&
            (collapsed ? (
              si > 0 && (
                <div className="mx-auto my-2 h-px w-6 bg-sidebar-border" />
              )
            ) : (
              <p className="mb-1 mt-4 px-3 text-[11px] font-semibold uppercase tracking-wider text-sidebar-foreground/40">
                {section.title}
              </p>
            ))}
          {section.items.map((item) => {
            const isExactMatch = pathname === item.href;
            const isPrefixMatch = pathname.startsWith(item.href + "/");
            const exactOnly =
              item.href === "/settings" ||
              item.href === "/payroll" ||
              item.href === "/attendance";
            const isActive = exactOnly
              ? isExactMatch
              : isExactMatch || isPrefixMatch;
            const Icon = item.icon;

            return (
              <Link
                key={item.href}
                href={item.href}
                onClick={onNavigate}
                aria-current={isActive ? "page" : undefined}
                title={collapsed ? item.label : undefined}
                className={cn(
                  "group relative flex items-center rounded-lg text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                  collapsed ? "h-10 w-10 justify-center" : "gap-3 px-3 py-2",
                  isActive
                    ? "bg-sidebar-accent text-sidebar-accent-foreground"
                    : "text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground",
                )}
              >
                {!collapsed && (
                  <span
                    aria-hidden
                    className={cn(
                      "absolute left-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full bg-primary transition-opacity",
                      isActive ? "opacity-100" : "opacity-0",
                    )}
                  />
                )}
                <Icon className="h-4 w-4 shrink-0" />
                {!collapsed && <span className="truncate">{item.label}</span>}
              </Link>
            );
          })}
        </div>
      ))}
    </nav>
  );
}
