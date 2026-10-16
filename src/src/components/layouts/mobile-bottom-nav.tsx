"use client";

import { useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  Building2,
  CalendarDays,
  CheckSquare,
  Clock,
  Landmark,
  Layers,
  LayoutDashboard,
  Menu,
  Receipt,
  ScrollText,
  Shield,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { useT } from "@/lib/i18n/useT";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useManagerDashboard } from "@/features/dashboard/api";
import { useOnboardingStatus } from "@/features/onboarding/useOnboardingStatus";
import { Sheet } from "@/components/ui/sheet";
import { SidebarNav } from "./sidebar-nav";
import { TenantLogoBadge } from "@/features/branding/TenantBrandingProvider";

/**
 * Mobile bottom tab bar per CLAUDE.md's Sidebar Navigation Structure
 * ("Mobile: bottom tab bar (Home, Attendance, Leave, Payslips, More) — not
 * sidebar"). "More" opens the full nav in a slide-in sheet, same pattern as
 * the header's hamburger menu, so every route stays reachable on mobile.
 */
export function MobileBottomNav() {
  const pathname = usePathname();
  const { t } = useT();
  const { isTenantAdmin, isSuperAdmin, isSupervisor } = usePermissions();
  // See sidebar-nav: a super admin clears the tenant-admin level check but has
  // no tenant, so onboarding progress does not exist for them.
  const onboarding = useOnboardingStatus(isTenantAdmin && !isSuperAdmin);
  // Same guard as the sidebar's badge: only approvers fetch the queue, and
  // never a super admin, who clears the level check with no tenant to count.
  const { data: managerData } = useManagerDashboard(
    isSupervisor && !isSuperAdmin,
  );
  const approvalCount = managerData?.pending_approvals?.total ?? 0;
  const [moreOpen, setMoreOpen] = useState(false);

  const showSetupDot =
    !onboarding.isLoading && !onboarding.isComplete && isTenantAdmin;

  // Same reasoning as the sidebar: a platform super admin has no tenant, so
  // Attendance / Leave / Payslips are not their work — the console is.
  const tabs = isSuperAdmin
    ? [
        {
          label: t("nav.admin", "Admin Console"),
          href: "/admin",
          icon: Shield,
        },
        {
          label: t("nav.tenants", "Tenants"),
          href: "/admin/tenants",
          icon: Building2,
        },
        {
          label: t("nav.platform_audit", "Audit Log"),
          href: "/admin/audit",
          icon: ScrollText,
        },
        {
          label: t("nav.platform_settings", "Settings"),
          href: "/admin/platform-settings",
          icon: Landmark,
        },
        {
          label: t("nav.plans", "Plans"),
          href: "/admin/plans",
          icon: Layers,
        },
      ]
    : [
        {
          label: t("nav.home", "Home"),
          href: "/dashboard",
          icon: LayoutDashboard,
        },
        {
          label: t("nav.attendance", "Attendance"),
          href: "/attendance",
          icon: Clock,
        },
        { label: t("nav.leave", "Leave"), href: "/leave", icon: CalendarDays },
        // Approvers get the pending queue where employees get payslips: on a
        // phone the time-sensitive tab earns the slot, a payslip is archival
        // and keeps its place in the More sheet. Everyone else keeps the
        // CLAUDE.md tab set exactly.
        isSupervisor
          ? {
              label: t("nav.approvals", "Approvals"),
              href: "/approvals",
              icon: CheckSquare,
              badge: approvalCount,
            }
          : {
              label: t("nav.payslips", "Payslips"),
              href: "/payroll/payslips",
              icon: Receipt,
            },
      ];

  return (
    <>
      <nav
        // The safe-area inset keeps the tabs clear of a phone's home
        // indicator now that the installed app draws edge to edge
        // (viewportFit: "cover").
        className="fixed inset-x-0 bottom-0 z-40 flex h-[calc(4rem+env(safe-area-inset-bottom))] items-start border-t border-border bg-background/95 pb-[env(safe-area-inset-bottom)] backdrop-blur supports-[backdrop-filter]:bg-background/85 lg:hidden"
        aria-label={t("nav.mobile_navigation", "Mobile navigation")}
      >
        {tabs.map((tab) => {
          const isActive =
            pathname === tab.href || pathname.startsWith(`${tab.href}/`);
          const Icon = tab.icon;
          const badge = "badge" in tab ? tab.badge : undefined;
          const hasBadge = badge != null && badge > 0;
          return (
            <Link
              key={tab.href}
              href={tab.href}
              aria-current={isActive ? "page" : undefined}
              aria-label={hasBadge ? `${tab.label} (${badge})` : undefined}
              className={cn(
                "flex h-16 flex-1 flex-col items-center justify-center gap-1 text-[11px] font-medium focus-visible:outline-none",
                isActive ? "text-interactive-primary" : "text-muted-foreground",
              )}
            >
              {/* The active tab's icon sits in a pill, the pattern phones
                  use for their own bottom bars; colour alone was a thin cue. */}
              <span
                className={cn(
                  "relative flex h-7 w-14 items-center justify-center rounded-full transition-colors",
                  isActive && "bg-primary-soft",
                )}
              >
                <Icon className="h-5 w-5" aria-hidden="true" />
                {hasBadge && (
                  <span
                    aria-hidden="true"
                    className="absolute -top-0.5 right-2 flex h-4 min-w-4 items-center justify-center rounded-full bg-status-error px-1 text-[10px] font-semibold tabular-nums leading-none text-text-inverse"
                  >
                    {badge > 99 ? "99+" : badge}
                  </span>
                )}
              </span>
              {tab.label}
            </Link>
          );
        })}
        <button
          type="button"
          onClick={() => setMoreOpen(true)}
          className="relative flex h-16 flex-1 flex-col items-center justify-center gap-1 text-[11px] font-medium text-muted-foreground focus-visible:outline-none"
        >
          <span className="flex h-7 w-14 items-center justify-center rounded-full">
            <Menu className="h-5 w-5" aria-hidden="true" />
          </span>
          {t("nav.more", "More")}
          {showSetupDot && (
            <span className="absolute right-1/4 top-1.5 flex h-2.5 w-2.5">
              <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand-accent opacity-50" />
              <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-brand-accent" />
            </span>
          )}
        </button>
      </nav>

      <Sheet
        open={moreOpen}
        onOpenChange={setMoreOpen}
        title={t("nav.more_navigation", "More navigation")}
      >
        <div className="flex h-16 items-center gap-2 border-b px-6">
          <TenantLogoBadge />
        </div>
        <div className="py-2">
          <SidebarNav onNavigate={() => setMoreOpen(false)} />
        </div>
      </Sheet>
    </>
  );
}
