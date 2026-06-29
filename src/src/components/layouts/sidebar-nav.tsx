'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
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
  UserCog,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { usePermissions } from '@/lib/hooks/usePermissions';

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
}

export function SidebarNav({ onNavigate }: SidebarNavProps) {
  const pathname = usePathname();
  const { can, isSupervisor, isFinanceAdmin, role } = usePermissions();

  const sections: NavSection[] = [
    {
      items: [
        { label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard, show: true },
      ],
    },
    {
      title: 'HR',
      items: [
        { label: 'Employees', href: '/employees', icon: Users, show: can.manageEmployees },
        { label: 'Organization', href: '/organization', icon: Building2, show: can.manageOrg },
      ],
    },
    {
      title: 'Operations',
      items: [
        { label: 'Attendance', href: '/attendance', icon: Clock, show: true },
        { label: 'Corrections', href: '/attendance/corrections', icon: FilePenLine, show: true },
        { label: 'Leave', href: '/leave', icon: CalendarDays, show: true },
        { label: 'Approvals', href: '/approvals', icon: CheckSquare, show: isSupervisor },
      ],
    },
    {
      title: 'Finance',
      items: [
        { label: 'Payroll Runs', href: '/payroll', icon: Wallet, show: can.viewPayrollRuns },
        { label: 'My Payslips', href: '/payroll/payslips', icon: Receipt, show: true },
      ],
    },
    {
      title: 'Insights',
      items: [
        { label: 'Reports', href: '/reports', icon: BarChart3, show: can.viewReports },
      ],
    },
    {
      title: 'System',
      items: [
        { label: 'Notifications', href: '/notifications', icon: Bell, show: true },
        { label: 'Settings', href: '/settings', icon: Settings, show: can.manageSettings },
        { label: 'Admin Console', href: '/admin', icon: Shield, show: can.viewAdminConsole },
      ],
    },
  ];

  const visibleSections = sections
    .map((s) => ({ ...s, items: s.items.filter((i) => i.show) }))
    .filter((s) => s.items.length > 0);

  return (
    <nav className="flex flex-col gap-1 px-3 py-2">
      {visibleSections.map((section, si) => (
        <div key={si}>
          {section.title && (
            <p className="mb-1 mt-4 px-3 text-[11px] font-semibold uppercase tracking-wider text-sidebar-foreground/40">
              {section.title}
            </p>
          )}
          {section.items.map((item) => {
            const isActive = pathname === item.href || pathname.startsWith(item.href + '/');
            const Icon = item.icon;

            return (
              <Link
                key={item.href}
                href={item.href}
                onClick={onNavigate}
                className={cn(
                  'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                  isActive
                    ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                    : 'text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground'
                )}
              >
                <Icon className="h-4 w-4 shrink-0" />
                {item.label}
              </Link>
            );
          })}
        </div>
      ))}

      <div className="mt-4 rounded-lg border border-sidebar-border/50 px-3 py-2">
        <p className="text-[10px] font-medium uppercase tracking-wider text-sidebar-foreground/40">Role</p>
        <p className="mt-0.5 text-xs font-medium capitalize text-sidebar-foreground/70">
          {role.replace(/_/g, ' ')}
        </p>
      </div>
    </nav>
  );
}
