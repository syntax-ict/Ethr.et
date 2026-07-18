"use client";

import { useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { CalendarDays, Clock, LayoutDashboard, Menu, Receipt } from "lucide-react";
import { cn } from "@/lib/utils";
import { useT } from "@/lib/i18n/useT";
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
  const [moreOpen, setMoreOpen] = useState(false);

  const tabs = [
    { label: t("nav.home", "Home"), href: "/dashboard", icon: LayoutDashboard },
    { label: t("nav.attendance", "Attendance"), href: "/attendance", icon: Clock },
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
              className={cn(
                "flex flex-1 flex-col items-center gap-1 py-2 text-xs font-medium",
                isActive
                  ? "text-interactive-primary"
                  : "text-muted-foreground",
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
          className="flex flex-1 flex-col items-center gap-1 py-2 text-xs font-medium text-muted-foreground"
        >
          <Menu className="h-5 w-5" />
          {t("nav.more", "More")}
        </button>
      </nav>

      <Sheet open={moreOpen} onOpenChange={setMoreOpen}>
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
