"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { cn } from "@/lib/utils";
import { useT } from "@/lib/i18n/useT";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { SETTINGS_GROUPS, settingsLandingFor } from "@/lib/navigation/registry";

// The Settings hub's own sub-navigation. It replaces the two collapsible
// "Settings" and "Integrations" groups that used to live in the main sidebar:
// configuration is a destination with its own left nav (the modern SaaS
// standard — Stripe, Vercel, Linear), keeping the primary sidebar lean.
// Each item is gated on the ability its page and API check, and the main
// sidebar links here whenever any item is visible.

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

/**
 * The settings pages the caller may open, grouped, each on the ability its API
 * checks. The list is `SETTINGS_GROUPS` in the navigation registry, which the
 * command palette searches too, so a page cannot be in one and not the other.
 * Shared with the sidebar and command palette, which link to the first one:
 * they offered Settings to `settings.manage` alone, so an HR admin, who holds
 * Users, Leave Types, Holidays, Shift Rules and Attendance Rules, had no way to
 * reach any of them (audit N91).
 */
export function useSettingsNavGroups(): SettingsNavGroup[] {
  const { t } = useT();
  const { can, isSuperAdmin } = usePermissions();
  const access = { can, isSuperAdmin };

  return SETTINGS_GROUPS.map((group) => ({
    title: t(group.titleKey, group.titleFallback),
    items: group.items
      .filter((item) => item.show(access))
      .map((item) => ({
        label: t(item.labelKey, item.labelFallback),
        href: item.navHref ?? item.href,
        icon: item.icon,
        show: true,
      })),
  })).filter((g) => g.items.length > 0);
}

/** Where "Settings" should lead the caller, or null when nothing is theirs. */
export function useSettingsLanding(): string | null {
  const { can, isSuperAdmin } = usePermissions();
  return settingsLandingFor({ can, isSuperAdmin });
}

export function SettingsNav() {
  const pathname = usePathname();
  const { t } = useT();
  const visibleGroups = useSettingsNavGroups();

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
