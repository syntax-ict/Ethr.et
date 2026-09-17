"use client";

import { useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  Building2,
  CalendarDays,
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
  const { isTenantAdmin, isSuperAdmin } = usePermissions();
  // See sidebar-nav: a super admin clears the tenant-admin level check but has
  // no tenant, so onboarding progress does not exist for them.
  const onboarding = useOnboardingStatus(isTenantAdmin && !isSuperAdmin);
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
        {
          label: t("nav.payslips", "Payslips"),
          href: "/payroll/payslips",
          icon: Receipt,
        },
      ];

  return (
    <>
      <nav
        className="fixed inset-x-0 bottom-0 z-40 flex h-16 items-center border-t border-border bg-background lg:hidden"
        aria-label={t("nav.mobile_navigation", "Mobile navigation")}
      >
        {tabs.map((tab) => {
          const isActive =
            pathname === tab.href || pathname.startsWith(`${tab.href}/`);
          const Icon = tab.icon;
          return (
            <Link
              key={tab.href}
              href={tab.href}
              aria-current={isActive ? "page" : undefined}
              className={cn(
                "flex flex-1 flex-col items-center gap-1 py-2 text-xs font-medium",
                isActive ? "text-interactive-primary" : "text-muted-foreground",
              )}
            >
              <Icon className="h-5 w-5" />
              {tab.label}
            </Link>
          );
        })}
        <button
          type="button"
          onClick={() => setMoreOpen(true)}
          className="relative flex flex-1 flex-col items-center gap-1 py-2 text-xs font-medium text-muted-foreground"
        >
          <Menu className="h-5 w-5" />
          {t("nav.more", "More")}
          {showSetupDot && (
            <span className="absolute right-1/4 top-1.5 flex h-2.5 w-2.5">
              <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-[var(--color-accent,#E8A838)] opacity-50" />
              <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-[var(--color-accent,#E8A838)]" />
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
