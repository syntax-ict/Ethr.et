"use client";

import { useState, useCallback, useEffect, useMemo } from "react";
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
  Receipt,
  FilePenLine,
  Shield,
  CheckSquare,
  Megaphone,
  Contact,
  Banknote,
  GraduationCap,
  TrendingUp,
  Fingerprint,
  UsersRound,
  CreditCard,
  CalendarRange,
  ChevronRight,
  Star,
  Rocket,
  ScrollText,
  Landmark,
  Layers,
  GitMerge,
} from "lucide-react";
import { cn } from "@/lib/utils";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { useNavPreferences } from "@/lib/hooks/useNavPreferences";
import { useOnboardingStatus } from "@/features/onboarding/useOnboardingStatus";
import { useManagerDashboard } from "@/features/dashboard/api";

// ── Types ────────────────────────────────────────────────────────────────────

interface NavLeaf {
  kind: "leaf";
  label: string;
  href: string;
  icon: React.ComponentType<{ className?: string }>;
  show: boolean;
  badge?: number;
}

interface NavParent {
  kind: "parent";
  /** Stable key for expand/collapse state. Not a route. */
  id: string;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  show: boolean;
  children: NavLeaf[];
}

type NavEntry = NavLeaf | NavParent;

interface NavSection {
  title?: string;
  entries: NavEntry[];
}

interface SidebarNavProps {
  onNavigate?: () => void;
  collapsed?: boolean;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function findLeafByHref(sections: NavSection[], href: string): NavLeaf | null {
  for (const section of sections) {
    for (const entry of section.entries) {
      if (entry.kind === "leaf" && entry.href === href && entry.show) {
        return entry;
      }
      if (entry.kind === "parent") {
        for (const child of entry.children) {
          if (child.href === href && child.show) return child;
        }
      }
    }
  }
  return null;
}

/**
 * Exactly one item may read as "you are here" (Nielsen #1, visibility of
 * system status). A naive `pathname.startsWith(href)` test lights up both
 * `/attendance` and `/attendance/team` at the same time, so instead we resolve
 * the single deepest href that prefixes the current path and compare against
 * that.
 */
function resolveActiveHref(sections: NavSection[], pathname: string): string {
  let best = "";
  const consider = (href: string) => {
    if (pathname === href || pathname.startsWith(href + "/")) {
      if (href.length > best.length) best = href;
    }
  };
  for (const section of sections) {
    for (const entry of section.entries) {
      if (entry.kind === "leaf") consider(entry.href);
      else entry.children.forEach((c) => consider(c.href));
    }
  }
  return best;
}

// ── Main Component ───────────────────────────────────────────────────────────

export function SidebarNav({ onNavigate, collapsed = false }: SidebarNavProps) {
  const pathname = usePathname();
  const { can, isSupervisor, isTenantAdmin, isSuperAdmin } = usePermissions();
  const { t } = useT();
  const {
    favorites,
    recent,
    toggleFavorite,
    isFavorite,
    trackVisit,
    recentExpanded,
    toggleRecentExpanded,
    favoritesAtLimit,
  } = useNavPreferences();
  // Not `isTenantAdmin` alone: that is a level check, and a super admin clears
  // it while belonging to no tenant at all — so the query fired for them and hit
  // an endpoint that has no tenant to report on.
  const onboarding = useOnboardingStatus(isTenantAdmin && !isSuperAdmin);

  // Badge counts. CLAUDE.md: "Items show notification count badges where
  // applicable (Approvals, Attendance anomalies)". Only fetched for roles that
  // can act on the queue.
  const { data: managerData } = useManagerDashboard(isSupervisor);
  const approvalCount = managerData?.pending_approvals?.total ?? 0;

  useEffect(() => {
    if (pathname && pathname !== "/dashboard") {
      trackVisit(pathname);
    }
  }, [pathname, trackVisit]);

  // ── Information Architecture ─────────────────────────────────────────────
  //
  // Sections follow the structure specified in CLAUDE.md (Overview / People /
  // Operations / Finance / Admin), plus a "My Work" band at the top.
  //
  // The split between "My Work" and "Operations" is by *persona*, not by data
  // domain: attendance appears in both, but "My Work > My Attendance" is the
  // employee's own record while "Operations > Attendance" holds the
  // administration of everyone else's. Grouping by who does the task keeps
  // each band scannable for the role that owns it, instead of forcing every
  // employee past nine admin screens to reach their own timesheet.

  const sections: NavSection[] = useMemo(() => {
    // A platform super admin belongs to no tenant, so every tenant destination
    // below ("My Attendance", "Employees", "Payroll Runs", …) is either empty or
    // an error for them — and on the platform hostname the middleware bounces
    // each one straight back to /admin. Showing seventeen links that all lead
    // home is worse than showing the four that work, so the console is the whole
    // navigation for this persona.
    if (isSuperAdmin) {
      return [
        {
          title: t("nav.section.platform", "Platform"),
          entries: [
            {
              kind: "leaf",
              label: t("nav.admin", "Admin Console"),
              href: "/admin",
              icon: Shield,
              show: true,
            },
            {
              kind: "leaf",
              label: t("nav.tenants", "Tenants"),
              href: "/admin/tenants",
              icon: Building2,
              show: true,
            },
            {
              kind: "leaf",
              label: t("nav.platform_audit", "Audit Log"),
              href: "/admin/audit",
              icon: ScrollText,
              show: true,
            },
            {
              kind: "leaf",
              label: t("nav.platform_settings", "Platform Settings"),
              href: "/admin/platform-settings",
              icon: Landmark,
              show: true,
            },
            {
              kind: "leaf",
              label: t("nav.plans", "Plans"),
              href: "/admin/plans",
              icon: Layers,
              show: true,
            },
          ],
        },
      ];
    }

    return [
      {
        entries: [
          {
            kind: "leaf",
            label: t("nav.dashboard", "Dashboard"),
            href: "/dashboard",
            icon: LayoutDashboard,
            show: true,
          },
        ],
      },
      {
        title: t("nav.section.my_work", "My Work"),
        entries: [
          {
            kind: "leaf",
            label: t("nav.my_attendance", "My Attendance"),
            href: "/attendance",
            icon: Clock,
            show: true,
          },
          // Approvals carries a live pending-count badge, so it sits directly
          // under the daily check-in: actionable, time-sensitive work belongs
          // near the top of the personal band (only rendered for approvers).
          {
            kind: "leaf",
            label: t("nav.approvals", "Approvals"),
            href: "/approvals",
            icon: CheckSquare,
            show: isSupervisor,
            badge: approvalCount,
          },
          {
            kind: "leaf",
            label: t("nav.leave", "Leave"),
            href: "/leave",
            icon: CalendarDays,
            show: true,
          },
          {
            kind: "leaf",
            label: t("nav.payslips", "My Payslips"),
            href: "/payroll/payslips",
            icon: Receipt,
            show: true,
          },
          {
            kind: "leaf",
            label: t("nav.announcements", "Announcements"),
            href: "/announcements",
            icon: Megaphone,
            show: true,
          },
        ],
      },
      {
        title: t("nav.section.people", "People"),
        entries: [
          {
            kind: "leaf",
            label: t("nav.employees", "Employees"),
            href: "/employees",
            icon: Users,
            show: can.manageEmployees,
          },
          {
            kind: "leaf",
            label: t("nav.organization", "Organization"),
            href: "/organization",
            icon: Building2,
            show: can.manageOrg,
          },
          {
            kind: "leaf",
            label: t("nav.directory", "Directory"),
            href: "/directory",
            icon: Contact,
            show: true,
          },
        ],
      },
      // The attendance, shifts and devices pages each render their own
      // exhaustive in-page tab strip. The sidebar therefore carries the area
      // plus only its highest-frequency sub-destinations rather than mirroring
      // every child: two complete, competing navigations for the same level
      // force the user to learn both and disagree on labels ("Intelligence" vs
      // "Anomalies", "Kiosk" vs "Kiosks"). Sidebar = shortcut layer, page tabs
      // = complete set.
      {
        title: t("nav.section.operations", "Operations"),
        entries: [
          {
            kind: "parent",
            id: "ops-attendance",
            label: t("nav.attendance", "Attendance"),
            icon: Clock,
            show: isSupervisor,
            children: [
              {
                kind: "leaf",
                label: t("nav.team_attendance", "Team Attendance"),
                href: "/attendance/team",
                icon: UsersRound,
                show: isSupervisor,
              },
              {
                kind: "leaf",
                label: t("nav.corrections", "Corrections"),
                href: "/attendance/corrections",
                icon: FilePenLine,
                show: true,
              },
              {
                kind: "leaf",
                label: t("nav.conflicts", "Conflicts"),
                href: "/attendance/conflicts",
                icon: GitMerge,
                show: can.viewAttendanceConflicts,
              },
              {
                kind: "leaf",
                label: t("nav.overtime", "Overtime"),
                href: "/attendance/overtime",
                icon: TrendingUp,
                show: can.manageEmployees,
              },
            ],
          },
          {
            kind: "leaf",
            label: t("nav.shifts", "Shifts & Schedules"),
            href: "/shifts",
            icon: CalendarRange,
            show: can.manageEmployees,
          },
          {
            kind: "leaf",
            label: t("nav.devices", "Devices"),
            href: "/devices",
            icon: Fingerprint,
            show: can.manageEmployees,
          },
        ],
      },
      {
        title: t("nav.section.finance", "Finance"),
        entries: [
          {
            kind: "leaf",
            label: t("nav.payroll_runs", "Payroll Runs"),
            href: "/payroll",
            icon: Wallet,
            show: can.viewPayrollRuns,
          },
          {
            kind: "leaf",
            label: t("nav.loans", "Loans"),
            href: "/payroll/loans",
            icon: Banknote,
            show: can.viewPayrollRuns,
          },
          {
            kind: "leaf",
            label: t("nav.cost_sharing", "Cost Sharing"),
            href: "/payroll/cost-sharing",
            icon: GraduationCap,
            show: can.viewPayrollRuns,
          },
          {
            kind: "leaf",
            label: t("nav.reports", "Reports"),
            href: "/reports",
            icon: BarChart3,
            show: can.viewReports,
          },
          {
            kind: "leaf",
            label: t("nav.analytics", "Analytics"),
            href: "/analytics",
            icon: TrendingUp,
            show: can.viewExecutiveDashboard || can.viewRegionalDashboard,
          },
        ],
      },
      {
        title: t("nav.section.admin", "Admin"),
        entries: [
          // Configuration lives behind a single destination with its own
          // sub-navigation (see components/layouts/settings-nav.tsx), the
          // modern SaaS pattern. This replaces the former "Settings" and
          // "Integrations" collapsible groups plus the Audit Log leaf — ~12
          // sidebar rows collapsed to one, with every route still reachable
          // inside the hub.
          {
            kind: "leaf",
            label: t("nav.settings", "Settings"),
            href: "/settings",
            icon: Settings,
            show: can.manageSettings,
          },
          {
            kind: "leaf",
            label: t("nav.billing", "Billing"),
            href: "/billing",
            icon: CreditCard,
            show: isTenantAdmin,
          },
          {
            kind: "parent",
            id: "admin-platform",
            label: t("nav.platform", "Platform"),
            icon: Shield,
            show: can.viewAdminConsole,
            children: [
              {
                kind: "leaf",
                label: t("nav.admin", "Admin Console"),
                href: "/admin",
                icon: Shield,
                show: can.viewAdminConsole,
              },
              {
                kind: "leaf",
                label: t("nav.tenants", "Tenants"),
                href: "/admin/tenants",
                icon: Building2,
                show: can.viewAdminConsole,
              },
              {
                kind: "leaf",
                label: t("nav.platform_audit", "Audit Log"),
                href: "/admin/audit",
                icon: ScrollText,
                show: can.viewAdminConsole,
              },
              {
                kind: "leaf",
                label: t("nav.platform_settings", "Platform Settings"),
                href: "/admin/platform-settings",
                icon: Landmark,
                show: can.viewAdminConsole,
              },
              {
                kind: "leaf",
                label: t("nav.plans", "Plans"),
                href: "/admin/plans",
                icon: Layers,
                show: can.viewAdminConsole,
              },
            ],
          },
        ],
      },
    ];
  }, [t, can, isSupervisor, isTenantAdmin, isSuperAdmin, approvalCount]);

  // ── Visibility filtering ─────────────────────────────────────────────────

  const visibleSections = useMemo(
    () =>
      sections
        .map((s) => ({
          ...s,
          entries: s.entries
            .map((e) => {
              if (e.kind === "parent") {
                if (!e.show) return null;
                const visibleChildren = e.children.filter((c) => c.show);
                if (visibleChildren.length === 0) return null;
                return { ...e, children: visibleChildren };
              }
              return e.show ? e : null;
            })
            .filter(Boolean) as NavEntry[],
        }))
        .filter((s) => s.entries.length > 0),
    [sections],
  );

  const activeHref = useMemo(
    () => resolveActiveHref(visibleSections, pathname),
    [visibleSections, pathname],
  );

  // ── Favorites & Recent ───────────────────────────────────────────────────

  const favoriteItems: NavLeaf[] = favorites
    .map((href) => findLeafByHref(visibleSections, href))
    .filter(Boolean) as NavLeaf[];

  const recentItems: NavLeaf[] = recent
    .filter(
      (r) =>
        !favorites.includes(r.href) &&
        r.href !== pathname &&
        r.href !== "/dashboard",
    )
    .slice(0, 3)
    .map((r) => findLeafByHref(visibleSections, r.href))
    .filter(Boolean) as NavLeaf[];

  // ── Expand/collapse state ────────────────────────────────────────────────

  const activeParent = visibleSections
    .flatMap((s) => s.entries)
    .find(
      (e): e is NavParent =>
        e.kind === "parent" && e.children.some((c) => c.href === activeHref),
    );

  const [expanded, setExpanded] = useState<Set<string>>(() => {
    const initial = new Set<string>();
    if (activeParent) initial.add(activeParent.id);
    return initial;
  });

  // Expand the parent of whatever route is active. The guard means this only
  // ever adds — it never fights a user-collapsed parent — so it's safe to run
  // during render on every route change without an effect. See
  // https://react.dev/learn/you-might-not-need-an-effect.
  if (activeParent && !expanded.has(activeParent.id)) {
    setExpanded((prev) => new Set(prev).add(activeParent.id));
  }

  const toggle = useCallback((id: string) => {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }, []);

  // ── Render ───────────────────────────────────────────────────────────────

  return (
    <nav
      aria-label={t("nav.main_navigation", "Main navigation")}
      className={cn("flex flex-col gap-0.5", collapsed ? "px-2" : "px-3 py-1")}
    >
      {/* Onboarding banner */}
      {!onboarding.isLoading && !onboarding.isComplete && isTenantAdmin && (
        <SetupBanner
          collapsed={collapsed}
          completedCount={onboarding.completedSteps.length}
          totalSteps={onboarding.totalSteps}
          pathname={pathname}
        />
      )}

      {/* Pinned favorites — labelled so unfamiliar rows at the top of the nav
          are explained rather than mysterious (Nielsen #2, #6). */}
      {favoriteItems.length > 0 && !collapsed && (
        <div className="flex flex-col gap-px">
          <p className="px-3 py-1 text-[11px] font-medium tracking-wide text-sidebar-foreground/60">
            {t("nav.section.pinned", "Pinned")}
          </p>
          {favoriteItems.map((item) => (
            <NavLink
              key={`fav-${item.href}`}
              item={item}
              activeHref={activeHref}
              onNavigate={onNavigate}
              collapsed={false}
              pinned
              onTogglePin={() => toggleFavorite(item.href)}
              variant="pinned"
            />
          ))}
        </div>
      )}

      {/* Recent — collapsed by default, toggleable */}
      {recentItems.length > 0 && !collapsed && (
        <div className="flex flex-col gap-px">
          <button
            type="button"
            onClick={toggleRecentExpanded}
            aria-expanded={recentExpanded}
            className="flex items-center gap-1.5 px-3 py-1 text-sidebar-foreground/60 transition-colors hover:text-sidebar-foreground/60"
          >
            <ChevronRight
              className={cn(
                "h-3 w-3 shrink-0 transition-transform duration-200",
                recentExpanded && "rotate-90",
              )}
            />
            <span className="text-[11px] font-medium tracking-wide">
              {t("nav.section.recent", "Recent")}
            </span>
          </button>
          <div
            className={cn(
              "grid transition-[grid-template-rows] duration-200 ease-in-out",
              recentExpanded ? "grid-rows-[1fr]" : "grid-rows-[0fr]",
            )}
          >
            <div className="overflow-hidden">
              <div className="flex flex-col gap-px pb-0.5">
                {recentItems.map((item) => (
                  <NavLink
                    key={`recent-${item.href}`}
                    item={item}
                    activeHref={activeHref}
                    onNavigate={onNavigate}
                    collapsed={false}
                    onTogglePin={() => toggleFavorite(item.href)}
                    variant="recent"
                    favoritesAtLimit={favoritesAtLimit}
                  />
                ))}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Separator between quick-access and main nav */}
      {(favoriteItems.length > 0 || recentItems.length > 0) && !collapsed && (
        <div className="my-1.5 h-px bg-sidebar-border/40" />
      )}

      {/* Main sections */}
      {visibleSections.map((section, si) => (
        <div key={section.title ?? `s-${si}`} className="flex flex-col gap-0.5">
          {section.title &&
            (collapsed ? (
              si > 0 && (
                <div className="mx-auto my-2 h-px w-6 bg-sidebar-border" />
              )
            ) : (
              <p className="mb-1 mt-5 px-3 text-[11px] font-semibold uppercase tracking-widest text-sidebar-foreground/60">
                {section.title}
              </p>
            ))}
          {!section.title && si > 0 && !collapsed && (
            <div className="my-2 h-px bg-sidebar-border/50" />
          )}
          {section.entries.map((entry) =>
            entry.kind === "leaf" ? (
              <NavLink
                key={entry.href}
                item={entry}
                activeHref={activeHref}
                onNavigate={onNavigate}
                collapsed={collapsed}
                isPinnable={!collapsed}
                pinned={isFavorite(entry.href)}
                onTogglePin={() => toggleFavorite(entry.href)}
                favoritesAtLimit={favoritesAtLimit}
              />
            ) : (
              <NavGroup
                key={entry.id}
                group={entry}
                activeHref={activeHref}
                isExpanded={expanded.has(entry.id)}
                onToggle={() => toggle(entry.id)}
                onNavigate={onNavigate}
                collapsed={collapsed}
                isFavorite={isFavorite}
                onTogglePin={toggleFavorite}
              />
            ),
          )}
        </div>
      ))}
    </nav>
  );
}

// ── NavLink ──────────────────────────────────────────────────────────────────

function NavLink({
  item,
  activeHref,
  onNavigate,
  collapsed,
  indent = false,
  isPinnable = false,
  pinned = false,
  onTogglePin,
  variant = "default",
  favoritesAtLimit = false,
}: {
  item: NavLeaf;
  activeHref: string;
  onNavigate?: () => void;
  collapsed: boolean;
  indent?: boolean;
  isPinnable?: boolean;
  pinned?: boolean;
  onTogglePin?: () => void;
  variant?: "default" | "pinned" | "recent";
  favoritesAtLimit?: boolean;
}) {
  const { t } = useT();
  const isActive = item.href === activeHref;
  const Icon = item.icon;
  const isSecondary = variant === "pinned" || variant === "recent";
  const hasBadge = item.badge != null && item.badge > 0;

  // Collapsed rows render the icon alone — the label span is dropped — so the
  // link has no text content and needs an explicit accessible name. It
  // previously relied on `title`, which is invisible to touch users, unreliable
  // for screen readers, and never a substitute for a name (WCAG 2.4.4 / 4.1.2).
  // The badge count is folded in because the collapsed badge is a bare dot.
  const collapsedLabel = hasBadge
    ? `${item.label} (${item.badge})`
    : item.label;

  const link = (
    <div className="group/pin relative flex items-center">
      <Link
        href={item.href}
        onClick={onNavigate}
        aria-current={isActive ? "page" : undefined}
        aria-label={collapsed ? collapsedLabel : undefined}
        className={cn(
          "relative flex flex-1 items-center rounded-lg font-medium transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
          collapsed ? "h-10 w-10 justify-center" : "gap-3",
          isSecondary ? "py-1 text-[13px]" : "py-1.5 text-sm",
          indent && !collapsed ? "pl-10 pr-3" : !collapsed && "px-3",
          isActive
            ? isSecondary
              ? "bg-sidebar-accent/70 text-sidebar-accent-foreground"
              : "bg-sidebar-accent text-sidebar-accent-foreground shadow-sm"
            : isSecondary
              ? "text-sidebar-foreground/70 hover:bg-sidebar-accent/30 hover:text-sidebar-foreground/80"
              : "text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground",
        )}
      >
        {!collapsed && !indent && !isSecondary && (
          <span
            aria-hidden
            className={cn(
              "absolute left-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full bg-primary transition-opacity duration-150",
              isActive ? "opacity-100" : "opacity-0",
            )}
          />
        )}
        {variant === "pinned" && !collapsed && (
          <Star
            className="h-3 w-3 shrink-0 text-[var(--color-accent,#E8A838)]"
            style={{ fill: "currentColor" }}
          />
        )}
        {variant !== "pinned" && (
          <Icon
            className={cn(
              "shrink-0 transition-colors duration-150",
              isSecondary ? "h-3.5 w-3.5" : "h-4 w-4",
              isActive ? "text-sidebar-accent-foreground" : "",
            )}
          />
        )}
        {!collapsed && (
          <span className="min-w-0 flex-1 truncate">{item.label}</span>
        )}
        {!collapsed && hasBadge && (
          <span
            className={cn(
              "ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-status-error px-1.5 text-[10px] font-bold text-text-inverse",
              // Keep clear of the hover-revealed pin button
              isPinnable && "group-hover/pin:mr-6",
            )}
          >
            {item.badge! > 99 ? "99+" : item.badge}
          </span>
        )}
        {collapsed && hasBadge && (
          <span
            aria-hidden
            className="absolute right-1 top-1 h-2 w-2 rounded-full bg-status-error ring-2 ring-sidebar"
          />
        )}
      </Link>
      {/* Pin/unpin toggle */}
      {variant === "pinned" && onTogglePin && !collapsed && (
        <button
          type="button"
          onClick={(e) => {
            e.stopPropagation();
            onTogglePin();
          }}
          className="absolute right-1 flex h-6 w-6 items-center justify-center rounded-md text-sidebar-foreground/60 opacity-0 transition-all duration-150 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground/60 group-hover/pin:opacity-100"
          title={t("nav.unpin", "Unpin")}
          aria-label={t("nav.unpin", "Unpin")}
        >
          <Star className="h-3 w-3" />
        </button>
      )}
      {isPinnable && onTogglePin && !collapsed && variant === "default" && (
        <button
          type="button"
          onClick={(e) => {
            e.stopPropagation();
            onTogglePin();
          }}
          className={cn(
            "absolute right-1 flex h-6 w-6 items-center justify-center rounded-md transition-all duration-150",
            pinned
              ? "text-[var(--color-accent,#E8A838)] opacity-100"
              : "opacity-0 hover:bg-sidebar-accent/50 group-hover/pin:opacity-100",
          )}
          title={
            pinned
              ? t("nav.unpin_favorites", "Unpin from favorites")
              : favoritesAtLimit
                ? t("nav.favorites_full", "Favorites full (max 5)")
                : t("nav.pin_favorites", "Pin to favorites")
          }
          aria-label={
            pinned
              ? t("nav.unpin_favorites", "Unpin from favorites")
              : favoritesAtLimit
                ? t("nav.favorites_full", "Favorites full (max 5)")
                : t("nav.pin_favorites", "Pin to favorites")
          }
          aria-pressed={pinned}
        >
          <Star
            className="h-3 w-3"
            style={pinned ? { fill: "currentColor" } : undefined}
          />
        </button>
      )}
      {variant === "recent" &&
        onTogglePin &&
        !collapsed &&
        !favoritesAtLimit && (
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              onTogglePin();
            }}
            className="absolute right-1 flex h-6 w-6 items-center justify-center rounded-md text-sidebar-foreground/60 opacity-0 transition-all duration-150 hover:text-[var(--color-accent,#E8A838)] group-hover/pin:opacity-100"
            title={t("nav.pin_favorites", "Pin to favorites")}
            aria-label={t("nav.pin_favorites", "Pin to favorites")}
          >
            <Star className="h-3 w-3" />
          </button>
        )}
    </div>
  );

  // Collapsed rows are icon-only, so the label needs a visible affordance too —
  // an `aria-label` alone serves assistive tech but leaves a sighted mouse user
  // guessing at a column of unlabelled glyphs.
  if (!collapsed) return link;

  return (
    <Tooltip>
      <TooltipTrigger asChild>{link}</TooltipTrigger>
      <TooltipContent side="right">{collapsedLabel}</TooltipContent>
    </Tooltip>
  );
}

// ── NavGroup ─────────────────────────────────────────────────────────────────

function NavGroup({
  group,
  activeHref,
  isExpanded,
  onToggle,
  onNavigate,
  collapsed,
  isFavorite,
  onTogglePin,
}: {
  group: NavParent;
  activeHref: string;
  isExpanded: boolean;
  onToggle: () => void;
  onNavigate?: () => void;
  collapsed: boolean;
  isFavorite: (href: string) => boolean;
  onTogglePin: (href: string) => void;
}) {
  const hasActiveChild = group.children.some((c) => c.href === activeHref);
  const Icon = group.icon;

  // Collapsed rail: a hover/focus flyout lists every child. Sending the click
  // straight to the first child (the previous behaviour) made children 2..n
  // unreachable without re-expanding the sidebar — the collapsed state cost
  // the user access rather than only screen width (Nielsen #7).
  if (collapsed) {
    return (
      <div className="group/fly relative">
        <button
          type="button"
          aria-haspopup="menu"
          className={cn(
            "flex h-10 w-10 items-center justify-center rounded-lg text-sm font-medium transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
            hasActiveChild
              ? "bg-sidebar-accent text-sidebar-accent-foreground shadow-sm"
              : "text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground",
          )}
        >
          <Icon className="h-4 w-4 shrink-0" />
          <span className="sr-only">{group.label}</span>
        </button>
        <div className="pointer-events-none absolute left-full top-0 z-50 hidden pl-2 group-focus-within/fly:pointer-events-auto group-focus-within/fly:block group-hover/fly:pointer-events-auto group-hover/fly:block">
          <div className="min-w-52 rounded-lg border border-sidebar-border bg-sidebar p-1.5 shadow-lg">
            <p className="px-2 py-1 text-[11px] font-semibold uppercase tracking-wider text-sidebar-foreground/60">
              {group.label}
            </p>
            {group.children.map((child) => {
              const ChildIcon = child.icon;
              const isActive = child.href === activeHref;
              return (
                <Link
                  key={child.href}
                  href={child.href}
                  onClick={onNavigate}
                  aria-current={isActive ? "page" : undefined}
                  className={cn(
                    "flex items-center gap-2.5 rounded-md px-2 py-1.5 text-[13px] font-medium transition-colors",
                    isActive
                      ? "bg-sidebar-accent text-sidebar-accent-foreground"
                      : "text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground",
                  )}
                >
                  <ChildIcon className="h-3.5 w-3.5 shrink-0" />
                  <span className="truncate">{child.label}</span>
                </Link>
              );
            })}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div>
      <button
        type="button"
        onClick={onToggle}
        aria-expanded={isExpanded}
        className={cn(
          "group relative flex w-full items-center gap-3 rounded-lg px-3 py-1.5 text-sm font-medium transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
          hasActiveChild
            ? "text-sidebar-accent-foreground"
            : "text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground",
        )}
      >
        {hasActiveChild && (
          <span
            aria-hidden
            className="absolute left-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full bg-primary"
          />
        )}
        <Icon className="h-4 w-4 shrink-0" />
        <span className="flex-1 truncate text-left">{group.label}</span>
        <ChevronRight
          className={cn(
            "h-3.5 w-3.5 shrink-0 text-sidebar-foreground/60 transition-transform duration-200",
            isExpanded && "rotate-90",
          )}
        />
      </button>
      <div
        className={cn(
          "grid transition-[grid-template-rows] duration-200 ease-in-out",
          isExpanded ? "grid-rows-[1fr]" : "grid-rows-[0fr]",
        )}
      >
        <div className="overflow-hidden">
          <div className="relative mt-0.5 flex flex-col gap-0.5 pb-0.5">
            <div
              aria-hidden
              className="absolute bottom-1 left-[1.625rem] top-0 w-px bg-sidebar-border/60"
            />
            {group.children.map((child) => (
              <NavLink
                key={child.href}
                item={child}
                activeHref={activeHref}
                onNavigate={onNavigate}
                collapsed={false}
                indent
                isPinnable
                pinned={isFavorite(child.href)}
                onTogglePin={() => onTogglePin(child.href)}
              />
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

// ── SetupBanner ─────────────────────────────────────────────────────────────

function SetupBanner({
  collapsed,
  completedCount,
  totalSteps,
  pathname,
}: {
  collapsed: boolean;
  completedCount: number;
  totalSteps: number;
  pathname: string;
}) {
  const { t } = useT();
  const isOnSetup = pathname === "/setup/guided";
  const progress =
    totalSteps > 0 ? Math.round((completedCount / totalSteps) * 100) : 0;

  if (collapsed) {
    return (
      <Link
        href="/setup/guided"
        title={t("sidebar.setup_progress", ":done of :total completed", {
          done: completedCount,
          total: totalSteps,
        })}
        className={cn(
          "relative flex h-10 w-10 items-center justify-center rounded-lg transition-all duration-150",
          isOnSetup
            ? "bg-sidebar-accent text-sidebar-accent-foreground shadow-sm"
            : "text-[var(--color-accent,#E8A838)] hover:bg-sidebar-accent/50",
        )}
      >
        <Rocket className="h-4 w-4" />
        <span className="absolute -right-0.5 -top-0.5 flex h-3 w-3">
          <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-[var(--color-accent,#E8A838)] opacity-50" />
          <span className="relative inline-flex h-3 w-3 rounded-full bg-[var(--color-accent,#E8A838)]" />
        </span>
      </Link>
    );
  }

  return (
    <div className="mb-2 flex flex-col gap-1.5">
      <Link
        href="/setup/guided"
        className={cn(
          "group flex flex-col gap-2 rounded-lg border px-3 py-2.5 transition-all duration-150",
          isOnSetup
            ? "border-[var(--color-accent,#E8A838)]/40 bg-[var(--color-accent,#E8A838)]/10"
            : "border-sidebar-border/50 bg-sidebar-accent/20 hover:border-[var(--color-accent,#E8A838)]/40 hover:bg-[var(--color-accent,#E8A838)]/10",
        )}
      >
        <div className="flex items-center gap-3">
          <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[var(--color-accent,#E8A838)]/20">
            <Rocket className="h-4 w-4 text-[var(--color-accent,#E8A838)]" />
          </div>
          <div className="min-w-0 flex-1">
            <p className="text-xs font-semibold text-sidebar-foreground">
              {t("sidebar.getting_started", "Getting Started")}
            </p>
            <p className="text-[10px] tabular-nums text-sidebar-foreground/70">
              {t("sidebar.steps_completed", ":done of :total completed", {
                done: completedCount,
                total: totalSteps,
              })}
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <div className="h-1.5 flex-1 rounded-full bg-sidebar-border/50">
            <div
              className="h-1.5 rounded-full bg-[var(--color-accent,#E8A838)] transition-all duration-500 ease-out"
              style={{ width: `${Math.max(progress, 2)}%` }}
            />
          </div>
        </div>

        <span className="flex items-center gap-1 text-[11px] font-medium text-[var(--color-accent,#E8A838)] transition-colors group-hover:text-[var(--color-interactive-hover,#3282B8)]">
          {t("sidebar.complete_setup", "Complete Setup")}
          <ChevronRight className="h-3 w-3 transition-transform group-hover:translate-x-0.5" />
        </span>
      </Link>
    </div>
  );
}
