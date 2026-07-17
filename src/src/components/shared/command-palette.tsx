"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import {
  LayoutDashboard,
  Users,
  Clock,
  CalendarDays,
  Wallet,
  BarChart3,
  Settings,
  Building2,
  Receipt,
  FilePenLine,
  Shield,
  CheckSquare,
  Megaphone,
  Contact,
  Banknote,
  Calendar,
  Fingerprint,
  KeyRound,
  Webhook,
  UserCircle,
  ScrollText,
  CreditCard,
  TrendingUp,
  Plus,
  Play,
  FileText,
  type LucideIcon,
} from "lucide-react";
import {
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from "@/components/ui/command";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";

interface PaletteItem {
  label: string;
  href?: string;
  icon: LucideIcon;
  keywords?: string;
  show: boolean;
}

interface PaletteGroup {
  heading: string;
  items: PaletteItem[];
}

export function CommandPalette() {
  const [open, setOpen] = useState(false);
  const router = useRouter();
  const { can, isSupervisor, isFinanceAdmin, isTenantAdmin } = usePermissions();
  const { t } = useT();

  useEffect(() => {
    function onKeyDown(e: KeyboardEvent) {
      if (e.key === "k" && (e.metaKey || e.ctrlKey)) {
        e.preventDefault();
        setOpen((prev) => !prev);
      }
    }
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, []);

  const navigate = useCallback(
    (href: string) => {
      setOpen(false);
      router.push(href);
    },
    [router],
  );

  const groups: PaletteGroup[] = useMemo(
    () => [
      {
        heading: t("command.nav", "Navigation"),
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
            label: t("nav.directory", "Directory"),
            href: "/directory",
            icon: Contact,
            keywords: "search people find",
            show: true,
          },
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
            keywords: "departments branches positions grades teams",
            show: can.manageOrg,
          },
          {
            label: t("nav.attendance", "Attendance"),
            href: "/attendance",
            icon: Clock,
            show: true,
          },
          {
            label: t("nav.corrections", "Corrections"),
            href: "/attendance/corrections",
            icon: FilePenLine,
            show: true,
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
            label: t("nav.payroll", "Payroll Runs"),
            href: "/payroll",
            icon: Wallet,
            show: can.viewPayrollRuns,
          },
          {
            label: t("nav.payslips", "My Payslips"),
            href: "/payroll/payslips",
            icon: Receipt,
            show: true,
          },
          {
            label: t("nav.loans", "Loans"),
            href: "/payroll/loans",
            icon: Banknote,
            show: isFinanceAdmin,
          },
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
          {
            label: t("nav.announcements", "Announcements"),
            href: "/announcements",
            icon: Megaphone,
            show: true,
          },
          {
            label: t("nav.devices", "Devices"),
            href: "/devices",
            icon: Fingerprint,
            show: can.manageEmployees,
          },
          {
            label: t("nav.holidays", "Holidays"),
            href: "/settings/holidays",
            icon: Calendar,
            show: can.manageEmployees,
          },
          {
            label: t("nav.settings", "Settings"),
            href: "/settings",
            icon: Settings,
            show: can.manageSettings,
          },
          {
            label: t("nav.billing", "Billing"),
            href: "/billing",
            icon: CreditCard,
            show: isTenantAdmin,
          },
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
            label: t("nav.audit_log", "Audit Log"),
            href: "/settings/audit-logs",
            icon: ScrollText,
            show: isTenantAdmin,
          },
          {
            label: t("nav.roles", "Roles"),
            href: "/settings/roles",
            icon: Shield,
            keywords: "permissions custom role",
            show: isTenantAdmin,
          },
          {
            label: t("nav.admin", "Admin Console"),
            href: "/admin",
            icon: Shield,
            show: can.viewAdminConsole,
          },
        ],
      },
      {
        heading: t("command.actions", "Quick Actions"),
        items: [
          {
            label: t("command.add_employee", "Add Employee"),
            href: "/employees/new",
            icon: Plus,
            keywords: "create new hire",
            show: can.manageEmployees,
          },
          {
            label: t("command.run_payroll", "Run Payroll"),
            href: "/payroll",
            icon: Play,
            keywords: "process salary",
            show: can.processPayroll,
          },
          {
            label: t("command.request_leave", "Request Leave"),
            href: "/leave/new",
            icon: CalendarDays,
            keywords: "time off vacation",
            show: true,
          },
          {
            label: t("command.generate_report", "Generate Report"),
            href: "/reports",
            icon: FileText,
            keywords: "export analytics",
            show: can.viewReports,
          },
        ],
      },
    ],
    [t, can, isSupervisor, isFinanceAdmin, isTenantAdmin],
  );

  return (
    <CommandDialog open={open} onOpenChange={setOpen}>
      <CommandInput
        placeholder={t("command.placeholder", "Type a command or search...")}
      />
      <CommandList>
        <CommandEmpty>
          {t("command.no_results", "No results found.")}
        </CommandEmpty>
        {groups.map((group, gi) => {
          const visible = group.items.filter((i) => i.show);
          if (visible.length === 0) return null;
          return (
            <div key={group.heading}>
              {gi > 0 && <CommandSeparator />}
              <CommandGroup heading={group.heading}>
                {visible.map((item) => {
                  const Icon = item.icon;
                  return (
                    <CommandItem
                      key={item.href ?? item.label}
                      value={`${item.label} ${item.keywords ?? ""}`}
                      onSelect={() => item.href && navigate(item.href)}
                    >
                      <Icon className="mr-2 h-4 w-4" />
                      {item.label}
                    </CommandItem>
                  );
                })}
              </CommandGroup>
            </div>
          );
        })}
      </CommandList>
    </CommandDialog>
  );
}
