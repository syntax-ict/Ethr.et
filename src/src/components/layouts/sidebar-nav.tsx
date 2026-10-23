"use client";

import { useState, useCallback, useEffect, useMemo } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { ChevronRight, ListChecks, Star } from "lucide-react";
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
import { useIntegritySummary } from "@/features/attendance/api";
import { useSettingsLanding } from "@/components/layouts/settings-nav";
import {
  type Destination,
  isoToday,
  type NavContext,
  navSections,
  resolveHref,
} from "@/lib/navigation/registry";

// quiet-on-error: the onboarding banner is a nudge; on a failed status request the sidebar draws without it rather than with an error in the navigation.

// ── Types ────────────────────────────────────────────────────────────────────

interface NavLeaf {
  kind: "leaf";
  label: string;
  href: string;
  icon: React.ComponentType<{ className?: string }>;
  show: boolean;
  badge?: number;
  /**
   * The group a child leaf belongs to, set only when the leaf is shown
   * outside its group (Pinned, Recent). Inside the group the heading already
   * says it; on its own, "Records" or "Corrections" does not say of what.
   */
  context?: string;
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
  /**
   * Row density. The mobile drawer passes "touch" for 44px rows (WCAG 2.5.8
   * comfortable target size on a phone); the desktop sidebar keeps its
   * tighter default.
   */
  size?: "default" | "touch";
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
          if (child.href === href && child.show) {
            return { ...child, context: entry.label };
          }
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

export function SidebarNav({
  onNavigate,
  collapsed = false,
  size = "default",
}: SidebarNavProps) {
  const pathname = usePathname();
  const { can, isSupervisor, isTenantAdmin, isSuperAdmin } = usePermissions();
  const settingsLanding = useSettingsLanding();
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
  // can act on the queue — and, like the onboarding query above, never for a
  // super admin, who clears the isSupervisor level check with no tenant queue
  // to count (the API answers 403).
  const { data: managerData } = useManagerDashboard(
    isSupervisor && !isSuperAdmin,
  );
  const approvalCount = managerData?.pending_approvals?.total ?? 0;
  // Attendance anomalies, the other count the conventions name: punches
  // waiting for an integrity review, for those who may review them.
  const { data: integrityData } = useIntegritySummary(
    can.viewAttendanceConflicts && !isSuperAdmin,
  );
  const integrityCount = integrityData?.open ?? 0;

  useEffect(() => {
    if (pathname && pathname !== "/dashboard") {
      trackVisit(pathname);
    }
  }, [pathname, trackVisit]);

  // ── Information Architecture ─────────────────────────────────────────────
  //
  // The tree itself lives in lib/navigation/registry.ts, where the command
  // palette reads the same list: one entry per destination, and the palette
  // cannot offer a page this sidebar would hide.

  // `can` is rebuilt on every render of usePermissions; its flags are what
  // matter, so the context keys on them.
  const canKey = JSON.stringify(can);
  const ctx: NavContext = useMemo(
    () => ({
      can,
      isSupervisor,
      isTenantAdmin,
      isSuperAdmin,
      settingsLanding,
      today: isoToday(),
    }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [canKey, isSupervisor, isTenantAdmin, isSuperAdmin, settingsLanding],
  );

  const sections: NavSection[] = useMemo(() => {
    const toLeaf = (d: Destination): NavLeaf => ({
      kind: "leaf",
      label: t(d.labelKey, d.labelFallback),
      href: resolveHref(d.href, ctx),
      icon: d.icon,
      show: d.show(ctx),
      badge:
        d.badge === "approvals"
          ? approvalCount
          : d.badge === "integrity"
            ? integrityCount
            : undefined,
    });
    return navSections(ctx).map((section) => ({
      title: section.titleKey
        ? t(section.titleKey, section.titleFallback)
        : undefined,
      entries: section.entries.map((entry): NavEntry => {
        if (entry.kind === "leaf") return toLeaf(entry);
        const parent: NavParent = {
          kind: "parent",
          id: entry.id,
          label: t(entry.labelKey, entry.labelFallback),
          icon: entry.icon,
          show: entry.show(ctx),
          children: entry.children.map(toLeaf),
        };
        return parent;
      }),
    }));
  }, [t, ctx, approvalCount, integrityCount]);

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
          <p className="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-sidebar-foreground/60">
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

      {/* Separator between the pinned rows and the main nav */}
      {favoriteItems.length > 0 && !collapsed && (
        <div className="mx-3 my-2 h-px bg-sidebar-border" />
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
              <p className="mb-1 mt-4 px-3 text-[11px] font-semibold uppercase tracking-wider text-sidebar-foreground/60">
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
                size={size}
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
                size={size}
                isFavorite={isFavorite}
                onTogglePin={toggleFavorite}
              />
            ),
          )}
        </div>
      ))}
      {/* Recent, collapsed by default, below the main nav: history is a
          shortcut, so it must not push the destinations down. */}
      {recentItems.length > 0 && !collapsed && (
        <div className="mt-3 flex flex-col gap-px border-t border-sidebar-border/50 pt-1">
          <button
            type="button"
            onClick={toggleRecentExpanded}
            aria-expanded={recentExpanded}
            className="flex items-center gap-1.5 rounded-md px-3 pb-1 pt-2 text-sidebar-foreground/60 transition-colors hover:text-sidebar-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <ChevronRight
              className={cn(
                "h-3 w-3 shrink-0 transition-transform duration-200",
                recentExpanded && "rotate-90",
              )}
            />
            <span className="text-[11px] font-semibold uppercase tracking-wider">
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
  size = "default",
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
  size?: "default" | "touch";
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
  const fullLabel = item.context ? `${item.context} ${item.label}` : item.label;

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
          "relative flex flex-1 items-center rounded-md font-medium transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
          collapsed ? "h-10 w-10 justify-center" : "gap-3",
          !collapsed &&
            (size === "touch"
              ? isSecondary
                ? "h-10 text-sm"
                : "h-11 text-sm"
              : isSecondary
                ? "h-8 text-[13px]"
                : "h-9 text-sm"),
          indent && !collapsed ? "pl-10 pr-3" : !collapsed && "px-3",
          isActive
            ? isSecondary
              ? "bg-sidebar-accent/70 text-sidebar-accent-foreground"
              : "bg-sidebar-accent text-sidebar-accent-foreground"
            : isSecondary
              ? "text-sidebar-foreground/70 hover:bg-sidebar-accent/30 hover:text-sidebar-foreground/80"
              : "text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground",
        )}
      >
        {!collapsed && !indent && !isSecondary && (
          <span
            aria-hidden
            className={cn(
              "absolute -left-3 top-1/2 h-5 w-[3px] -translate-y-1/2 rounded-r-full bg-brand-accent transition-opacity duration-150",
              isActive ? "opacity-100" : "opacity-0",
            )}
          />
        )}
        {variant === "pinned" && !collapsed && (
          <Star
            className="h-3 w-3 shrink-0 text-brand-accent"
            style={{ fill: "currentColor" }}
          />
        )}
        {variant !== "pinned" && (
          <Icon
            className={cn(
              "shrink-0 transition-colors duration-150",
              isSecondary ? "h-3.5 w-3.5" : "h-4 w-4",
              isActive
                ? "text-sidebar-accent-foreground"
                : "text-sidebar-foreground/60",
            )}
          />
        )}
        {!collapsed &&
          (item.context ? (
            // The group comes first and muted, so the item still reads as the
            // destination; the chevron is an icon, not text, so the visible
            // name stays "Attendance Records" for search and assistive tech.
            <span className="flex min-w-0 flex-1 items-center gap-1">
              <span className="max-w-[45%] shrink-0 truncate text-sidebar-foreground/60">
                {item.context}
              </span>
              <ChevronRight
                className="h-3 w-3 shrink-0 text-sidebar-foreground/40"
                aria-hidden="true"
              />
              <span className="min-w-0 flex-1 truncate">{item.label}</span>
            </span>
          ) : (
            <span className="min-w-0 flex-1 truncate">{item.label}</span>
          ))}
        {!collapsed && hasBadge && (
          <CountBadge
            count={item.badge!}
            // Keep clear of the hover-revealed pin button
            className={cn("ml-auto", isPinnable && "group-hover/pin:mr-6")}
          />
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
          aria-label={`${t("nav.unpin", "Unpin")}: ${fullLabel}`}
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
              ? "text-brand-accent opacity-100"
              : "opacity-0 hover:bg-sidebar-accent/50 group-hover/pin:opacity-100",
          )}
          title={
            pinned
              ? t("nav.unpin_favorites", "Unpin from favorites")
              : favoritesAtLimit
                ? t("nav.favorites_full", "Favorites full (max 5)")
                : t("nav.pin_favorites", "Pin to favorites")
          }
          aria-label={`${
            pinned
              ? t("nav.unpin_favorites", "Unpin from favorites")
              : favoritesAtLimit
                ? t("nav.favorites_full", "Favorites full (max 5)")
                : t("nav.pin_favorites", "Pin to favorites")
          }: ${fullLabel}`}
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
            className="absolute right-1 flex h-6 w-6 items-center justify-center rounded-md text-sidebar-foreground/60 opacity-0 transition-all duration-150 hover:text-brand-accent group-hover/pin:opacity-100"
            title={t("nav.pin_favorites", "Pin to favorites")}
            aria-label={`${t("nav.pin_favorites", "Pin to favorites")}: ${fullLabel}`}
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

// ── CountBadge ───────────────────────────────────────────────────────────────

/** The red count on a row, a closed group, or a flyout row: one look for all. */
function CountBadge({
  count,
  className,
}: {
  count: number;
  className?: string;
}) {
  return (
    <span
      className={cn(
        "flex h-5 min-w-5 items-center justify-center rounded-full bg-status-error px-1.5 text-[11px] font-semibold tabular-nums leading-none text-text-inverse",
        className,
      )}
    >
      {count > 99 ? "99+" : count}
    </span>
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
  size = "default",
  isFavorite,
  onTogglePin,
}: {
  group: NavParent;
  activeHref: string;
  isExpanded: boolean;
  onToggle: () => void;
  onNavigate?: () => void;
  collapsed: boolean;
  size?: "default" | "touch";
  isFavorite: (href: string) => boolean;
  onTogglePin: (href: string) => void;
}) {
  const hasActiveChild = group.children.some((c) => c.href === activeHref);
  const Icon = group.icon;
  // A closed group still says that something inside it needs a hand; open,
  // the count sits on the child it belongs to.
  const childCount = group.children.reduce((n, c) => n + (c.badge ?? 0), 0);

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
            "relative flex h-10 w-10 items-center justify-center rounded-lg text-sm font-medium transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
            hasActiveChild
              ? "bg-sidebar-accent text-sidebar-accent-foreground"
              : "text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground",
          )}
        >
          <Icon className="h-4 w-4 shrink-0" />
          <span className="sr-only">
            {childCount > 0 ? `${group.label} (${childCount})` : group.label}
          </span>
          {childCount > 0 && (
            <span
              aria-hidden
              className="absolute right-1 top-1 h-2 w-2 rounded-full bg-status-error ring-2 ring-sidebar"
            />
          )}
        </button>
        <div className="pointer-events-none absolute left-full top-0 z-50 hidden pl-2 group-focus-within/fly:pointer-events-auto group-focus-within/fly:block group-hover/fly:pointer-events-auto group-hover/fly:block">
          <div className="min-w-52 rounded-lg border border-sidebar-border bg-sidebar p-1.5 shadow-xl">
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
                  <span className="flex-1 truncate">{child.label}</span>
                  {child.badge != null && child.badge > 0 && (
                    <CountBadge count={child.badge} className="ml-auto" />
                  )}
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
          "group relative flex w-full items-center gap-3 rounded-md px-3 text-sm font-medium transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
          size === "touch" ? "h-11" : "h-9",
          hasActiveChild
            ? "text-sidebar-accent-foreground"
            : "text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground",
        )}
      >
        {hasActiveChild && (
          <span
            aria-hidden
            className="absolute -left-3 top-1/2 h-5 w-[3px] -translate-y-1/2 rounded-r-full bg-brand-accent"
          />
        )}
        <Icon className="h-4 w-4 shrink-0" />
        <span className="flex-1 truncate text-left">{group.label}</span>
        {!isExpanded && childCount > 0 && <CountBadge count={childCount} />}
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
                size={size}
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

const SETUP_BANNER_KEY = "ethr.setup-banner-open";

function readSetupBannerOpen(): boolean {
  if (typeof window === "undefined") return true;
  try {
    return localStorage.getItem(SETUP_BANNER_KEY) !== "0";
  } catch {
    return true;
  }
}

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
  // Open by default so a new tenant discovers it; the choice to tuck it away
  // is remembered per browser, like the sidebar's own collapsed state. The
  // row itself stays a link either way, so one tap still reaches setup and
  // the count keeps reporting progress even when the detail is tucked.
  const [detailOpen, setDetailOpen] = useState(readSetupBannerOpen);
  const toggleDetail = useCallback(() => {
    setDetailOpen((prev) => {
      try {
        localStorage.setItem(SETUP_BANNER_KEY, prev ? "0" : "1");
      } catch {
        // Private windows may refuse; the toggle still works for the session.
      }
      return !prev;
    });
  }, []);

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
            : "text-brand-accent hover:bg-sidebar-accent/50",
        )}
      >
        <ListChecks className="h-4 w-4" aria-hidden="true" />
        {/* A steady dot, not a pulsing one: setup is unfinished, not urgent. */}
        <span className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-brand-accent" />
      </Link>
    );
  }

  return (
    <div
      className={cn(
        "mb-2 rounded-lg border transition-colors duration-150",
        isOnSetup
          ? "border-brand-accent/40 bg-sidebar-accent"
          : "border-sidebar-border bg-sidebar-accent/40",
      )}
    >
      <div className="flex items-center">
        <Link
          href="/setup/guided"
          aria-current={isOnSetup ? "page" : undefined}
          className="group flex min-w-0 flex-1 items-center gap-2.5 rounded-lg p-2.5 transition-colors duration-150 hover:bg-sidebar-accent/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <ListChecks
            className="h-4 w-4 shrink-0 text-sidebar-foreground/70"
            aria-hidden="true"
          />
          <p className="min-w-0 flex-1 truncate text-xs font-semibold text-sidebar-foreground">
            {t("sidebar.getting_started", "Getting Started")}
          </p>
          <span className="shrink-0 text-[11px] tabular-nums text-sidebar-foreground/70">
            {completedCount}/{totalSteps}
          </span>
        </Link>
        <button
          type="button"
          onClick={toggleDetail}
          aria-expanded={detailOpen}
          aria-label={t("sidebar.setup_toggle", "Setup progress")}
          className="mr-1.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-sidebar-foreground/60 transition-colors hover:bg-sidebar-accent/70 hover:text-sidebar-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <ChevronRight
            className={cn(
              "h-3.5 w-3.5 transition-transform duration-200",
              detailOpen && "rotate-90",
            )}
            aria-hidden="true"
          />
        </button>
      </div>

      <div
        className={cn(
          "grid transition-[grid-template-rows] duration-200 ease-in-out",
          detailOpen ? "grid-rows-[1fr]" : "grid-rows-[0fr]",
        )}
      >
        <div className="overflow-hidden">
          <div className="px-2.5 pb-2.5">
            <p className="truncate pb-1.5 text-[11px] tabular-nums text-sidebar-foreground/70">
              {t("sidebar.steps_completed", ":done of :total completed", {
                done: completedCount,
                total: totalSteps,
              })}
            </p>
            <div
              className="h-1 overflow-hidden rounded-full bg-sidebar-border"
              role="progressbar"
              aria-label={t("sidebar.complete_setup", "Complete Setup")}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-valuenow={progress}
            >
              <div
                className="h-full rounded-full bg-brand-accent transition-[width] duration-500 ease-out"
                style={{ width: `${Math.max(progress, 4)}%` }}
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
