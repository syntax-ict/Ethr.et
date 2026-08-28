"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  SlidersHorizontal,
  Clock,
  Timer,
  ListChecks,
  Calendar,
  Coins,
  ShieldCheck,
  KeyRound,
  Webhook,
  BookOpen,
  Mail,
  ScrollText,
  UserCog,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { useT } from "@/lib/i18n/useT";
import { usePermissions } from "@/lib/hooks/usePermissions";

// The Settings hub's own sub-navigation. It replaces the two collapsible
// "Settings" and "Integrations" groups that used to live in the main sidebar:
// configuration is a destination with its own left nav (the modern SaaS
// standard — Stripe, Vercel, Linear), keeping the primary sidebar lean.
// Per-item gates mirror the previous sidebar entries so no role gains or loses
// access; in practice only tenant_admin+ ever reach this layout.

interface SettingsNavItem {
  label: string;
  href: string;
  icon: React.ComponentType<{ className?: string }>;
  show: boolean;
}

interface SettingsNavGroup {
  title: string;
  items: SettingsNavItem[];
}

/**
 * Resolve the single deepest href that prefixes the current path, so exactly
 * one row reads as active (mirrors the main sidebar's active-link logic).
 */
function resolveActiveHref(
  groups: SettingsNavGroup[],
  pathname: string,
): string {
  let best = "";
  for (const group of groups) {
    for (const item of group.items) {
      if (!item.show) continue;
      if (pathname === item.href || pathname.startsWith(item.href + "/")) {
        if (item.href.length > best.length) best = item.href;
      }
    }
  }
  return best;
}

export function SettingsNav() {
  const pathname = usePathname();
  const { t } = useT();
  const { can, isTenantAdmin } = usePermissions();

  const groups: SettingsNavGroup[] = [
    {
      title: t("settings.nav.workspace", "Workspace"),
      items: [
        {
          label: t("nav.general", "General"),
          href: "/settings",
          icon: SlidersHorizontal,
          show: can.manageSettings,
        },
      ],
    },
    {
      title: t("settings.nav.configuration", "Configuration"),
      items: [
        {
          label: t("nav.attendance_rules", "Attendance Rules"),
          href: "/attendance/settings",
          icon: Clock,
          show: can.manageEmployees,
        },
        {
          label: t("nav.shift_config", "Shift Rules"),
          href: "/settings/shifts",
          icon: Timer,
          show: can.manageEmployees,
        },
        {
          label: t("nav.leave_types", "Leave Types"),
          href: "/settings/leave-types",
          icon: ListChecks,
          show: can.manageEmployees,
        },
        {
          label: t("nav.holidays", "Holidays"),
          href: "/settings/holidays",
          icon: Calendar,
          show: can.manageEmployees,
        },
        {
          label: t("nav.payroll_config", "Payroll Rules"),
          href: "/settings/payroll",
          icon: Coins,
          show: isTenantAdmin,
        },
      ],
    },
    {
      title: t("settings.nav.access", "Access & Security"),
      items: [
        {
          label: t("nav.users", "Users & Access"),
          href: "/settings/users",
          icon: UserCog,
          show: isTenantAdmin,
        },
        {
          label: t("nav.roles", "Roles & Permissions"),
          href: "/settings/roles",
          icon: ShieldCheck,
          show: isTenantAdmin,
        },
      ],
    },
    {
      title: t("nav.integrations", "Integrations"),
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
          label: t("nav.scim", "SCIM Provisioning"),
          href: "/settings/scim",
          icon: UserCog,
          show: isTenantAdmin,
        },
      ],
    },
    {
      title: t("settings.nav.compliance", "Compliance"),
      items: [
        {
          label: t("nav.audit_log", "Audit Log"),
          href: "/settings/audit-logs",
          icon: ScrollText,
          show: isTenantAdmin,
        },
      ],
    },
  ];

  const visibleGroups = groups
    .map((g) => ({ ...g, items: g.items.filter((i) => i.show) }))
    .filter((g) => g.items.length > 0);

  const activeHref = resolveActiveHref(visibleGroups, pathname);

  return (
    <nav
      aria-label={t("settings.nav.label", "Settings navigation")}
      className="flex gap-1 overflow-x-auto pb-2 lg:flex-col lg:gap-5 lg:overflow-visible lg:pb-0"
    >
      {visibleGroups.map((group) => (
        <div
          key={group.title}
          className="flex shrink-0 gap-1 lg:flex-col lg:gap-0.5"
        >
          <p className="hidden px-3 pb-1 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground lg:block">
            {group.title}
          </p>
          {group.items.map((item) => {
            const Icon = item.icon;
            const isActive = item.href === activeHref;
            return (
              <Link
                key={item.href}
                href={item.href}
                aria-current={isActive ? "page" : undefined}
                className={cn(
                  "flex shrink-0 items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                  isActive
                    ? "bg-primary/10 text-interactive-primary"
                    : "text-muted-foreground hover:bg-muted hover:text-foreground",
                )}
              >
                <Icon className="h-4 w-4 shrink-0" />
                <span className="whitespace-nowrap lg:whitespace-normal">
                  {item.label}
                </span>
              </Link>
            );
          })}
        </div>
      ))}
    </nav>
  );
}
