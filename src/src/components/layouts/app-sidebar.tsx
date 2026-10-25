"use client";

import { useRef, useCallback, useEffect, useState } from "react";
import Link from "next/link";
import {
  ChevronsUpDown,
  CreditCard,
  LogOut,
  PanelLeftClose,
  PanelLeftOpen,
  Settings,
  ShieldCheck,
} from "lucide-react";
import { SidebarNav } from "./sidebar-nav";
import { TenantLogoBadge } from "@/features/branding/TenantBrandingProvider";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { useAppLayout } from "./layout-context";
import {
  useCurrentUser,
  useCurrentTenant,
  useLogout,
} from "@/features/auth/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { SITE_URL } from "@/lib/site-url";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

function useScrollFades() {
  const ref = useRef<HTMLDivElement>(null);
  const [canScrollUp, setCanScrollUp] = useState(false);
  const [canScrollDown, setCanScrollDown] = useState(false);

  const update = useCallback(() => {
    const el = ref.current;
    if (!el) return;
    setCanScrollUp(el.scrollTop > 8);
    setCanScrollDown(el.scrollTop + el.clientHeight < el.scrollHeight - 8);
  }, []);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    update();
    el.addEventListener("scroll", update, { passive: true });
    const ro = new ResizeObserver(update);
    ro.observe(el);
    return () => {
      el.removeEventListener("scroll", update);
      ro.disconnect();
    };
  }, [update]);

  return { ref, canScrollUp, canScrollDown };
}

/**
 * Workspace menu — the top-left switcher. Previously a decorative button with a
 * chevron that opened nothing; now a real menu (workspace identity + admin
 * shortcuts + sign out), the professional-standard behaviour that the
 * ChevronsUpDown affordance promises. Personal account actions stay in the
 * header avatar menu; this one is scoped to the workspace.
 */
function WorkspaceMenu({
  children,
  tenantName,
  roleName,
  align = "start",
}: {
  children: React.ReactNode;
  tenantName: string;
  roleName: string;
  align?: "start" | "center" | "end";
}) {
  const { t } = useT();
  const { can, isTenantAdmin } = usePermissions();
  const logout = useLogout();
  const hasAdminLinks = can.manageSettings || isTenantAdmin;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>{children}</DropdownMenuTrigger>
      <DropdownMenuContent align={align} sideOffset={8} className="w-60">
        <DropdownMenuLabel className="font-normal">
          <div className="flex items-center gap-2.5">
            <TenantLogoBadge showName={false} surface="dark" />
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-semibold">{tenantName}</p>
              {roleName && (
                <p className="truncate text-xs capitalize text-muted-foreground">
                  {roleName}
                </p>
              )}
            </div>
          </div>
        </DropdownMenuLabel>
        {hasAdminLinks && (
          <>
            <DropdownMenuSeparator />
            {can.manageSettings && (
              <DropdownMenuItem asChild>
                <Link href="/settings" className="cursor-pointer gap-2">
                  <Settings className="h-4 w-4 text-muted-foreground" />
                  {t("nav.workspace_settings", "Workspace settings")}
                </Link>
              </DropdownMenuItem>
            )}
            {isTenantAdmin && (
              <DropdownMenuItem asChild>
                <Link href="/billing" className="cursor-pointer gap-2">
                  <CreditCard className="h-4 w-4 text-muted-foreground" />
                  {t("nav.billing", "Billing")}
                </Link>
              </DropdownMenuItem>
            )}
          </>
        )}
        <DropdownMenuSeparator />
        <DropdownMenuItem
          className="cursor-pointer gap-2 text-destructive focus:text-destructive"
          onClick={() => logout.mutate()}
        >
          <LogOut className="h-4 w-4" />
          {t("common.logout", "Log Out")}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

export function AppSidebar() {
  const { collapsed, toggleCollapsed } = useAppLayout();
  const { data: user } = useCurrentUser();
  const { data: tenant } = useCurrentTenant();
  const { t } = useT();
  // Destructured rather than held as a `scroll` object: the hook returns a ref
  // alongside two pieces of state, and reading `canScrollUp` in the render
  // body made the compiler treat every property access on that object as a
  // ref read (react-hooks/refs). Pulling them apart lets it see the state values
  // for what they are.
  const { ref: scrollRef, canScrollUp, canScrollDown } = useScrollFades();
  // No shortcut chips and no palette trigger down here: the header's search
  // opens the palette, and the shortcuts sheet (press ?) lists both keys. The
  // footer is the product mark and one icon, nothing a user has to read.
  const poweredBy = t("auth.powered_by", "Powered by ETHR");
  const toggleLabel = collapsed
    ? t("sidebar.expand", "Expand sidebar")
    : t("sidebar.collapse", "Collapse sidebar");

  const roleName = user?.role
    ? t(`role.${user.role}`, user.role.replace(/_/g, " "))
    : "";
  const tenantName = tenant?.name ?? "ETHR";
  const tenantInitial = tenantName.charAt(0).toUpperCase();
  // The collapsed button shows only an initial, so it is named; its name
  // starts with that initial (WCAG 2.5.3, label in name). The expanded one
  // is named by what it shows, organisation and role, and aria-haspopup
  // says it opens a menu.
  const workspaceLabel = t(
    "nav.workspace_menu_named",
    ":name, workspace menu",
    {
      name: tenantName,
    },
  );

  return (
    <aside
      aria-label={t("nav.main_navigation", "Main navigation")}
      data-collapsed={collapsed}
      className={cn(
        "hidden shrink-0 border-r border-sidebar-border bg-sidebar text-sidebar-foreground transition-[width] duration-200 ease-in-out lg:flex lg:flex-col",
        collapsed ? "w-16" : "w-64",
      )}
    >
      {/* Workspace switcher header — shrink-0 keeps it pinned at the top; h-14
          matches the topbar so the two align on one horizontal seam. */}
      <div
        className={cn(
          "flex h-14 shrink-0 items-center border-b border-sidebar-border",
          collapsed ? "justify-center px-2" : "px-3",
        )}
      >
        {!collapsed ? (
          <WorkspaceMenu tenantName={tenantName} roleName={roleName}>
            <button
              type="button"
              className="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 transition-colors hover:bg-sidebar-accent/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring data-[state=open]:bg-sidebar-accent"
            >
              <TenantLogoBadge showName={false} surface="dark" />
              <div className="min-w-0 flex-1 text-left">
                <p className="truncate text-sm font-semibold text-sidebar-foreground">
                  {tenantName}
                </p>
                {roleName && (
                  <p className="truncate text-xs capitalize text-sidebar-foreground/70">
                    {roleName}
                  </p>
                )}
              </div>
              <ChevronsUpDown className="h-4 w-4 shrink-0 text-sidebar-foreground/60" />
            </button>
          </WorkspaceMenu>
        ) : (
          <WorkspaceMenu tenantName={tenantName} roleName={roleName}>
            <button
              type="button"
              title={tenantName}
              aria-label={workspaceLabel}
              className="flex h-8 w-8 items-center justify-center rounded-lg bg-sidebar-accent text-sm font-bold text-sidebar-accent-foreground shadow-sm transition-shadow hover:shadow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring data-[state=open]:ring-2 data-[state=open]:ring-ring"
            >
              {tenantInitial}
            </button>
          </WorkspaceMenu>
        )}
      </div>

      {/* Navigation — min-h-0 lets flex-1 shrink below content height */}
      <div
        className="sidebar-scroll-container min-h-0 flex-1"
        data-can-scroll-up={canScrollUp}
        data-can-scroll-down={canScrollDown}
      >
        <div
          ref={scrollRef}
          className="sidebar-scroll h-full overflow-y-auto overflow-x-hidden py-2"
        >
          <SidebarNav collapsed={collapsed} />
        </div>
      </div>

      {/* Footer — shrink-0 keeps it pinned at the bottom. One row, not two:
          the search trigger fills it and the collapse toggle is a square icon
          button beside it, named by its tooltip (the pattern Notion, Slack and
          the shadcn sidebar settle on). The old stack of two labelled rows
          cost 95px of a column that already scrolls at 1000px tall. */}
      <div className="shrink-0 border-t border-sidebar-border">
        {/* Ethiopian tibeb pattern accent — the design system's signature
            element (CLAUDE.md, Visual Identity).

            Alpha comes from `color-mix`, not `var(--x)/0.15`. The slash-alpha
            shorthand is only valid on a bare colour token, not on a `var()`
            reference: the previous markup compiled to
            `var(--sidebar-foreground)/.15 0px`, which is invalid CSS, so every
            browser discarded the whole gradient and the pattern never rendered
            at all. `color-mix` is the same technique globals.css already uses
            for `::selection`. The mix is 25% foreground and 60% gold: at
            15%/35% the line measured as a flat border on the dark surface, so
            a signature element was invisible in the one place it lives. */}
        <div
          aria-hidden="true"
          className="h-0.5 w-full bg-[repeating-linear-gradient(90deg,color-mix(in_srgb,var(--sidebar-foreground)_25%,transparent)_0px,color-mix(in_srgb,var(--sidebar-foreground)_25%,transparent)_6px,color-mix(in_srgb,var(--brand-accent)_60%,transparent)_6px,color-mix(in_srgb,var(--brand-accent)_60%,transparent)_12px,color-mix(in_srgb,var(--sidebar-foreground)_25%,transparent)_12px,color-mix(in_srgb,var(--sidebar-foreground)_25%,transparent)_18px,transparent_18px,transparent_24px)]"
        />

        <div
          className={cn(
            "flex gap-1 p-2",
            collapsed ? "flex-col items-center" : "items-center",
          )}
        >
          {/* The product mark. The header above names the organisation, so
              the foot of the column is where ETHR signs its work, the same
              line and icon the sign-in pages carry. It links to the public
              site in a new tab, so an organisation's own page is never
              navigated away from. */}
          {collapsed ? (
            <Tooltip>
              <TooltipTrigger asChild>
                <a
                  href={SITE_URL}
                  target="_blank"
                  rel="noopener noreferrer"
                  aria-label={poweredBy}
                  className={railButtonClass}
                >
                  <ShieldCheck className="h-4 w-4" aria-hidden="true" />
                </a>
              </TooltipTrigger>
              <TooltipContent side="right">{poweredBy}</TooltipContent>
            </Tooltip>
          ) : (
            <a
              href={SITE_URL}
              target="_blank"
              rel="noopener noreferrer"
              className="flex h-9 min-w-0 flex-1 items-center gap-2 rounded-md px-2.5 text-[11px] font-medium tracking-wide text-sidebar-foreground/70 transition-colors hover:bg-sidebar-accent/40 hover:text-sidebar-foreground/85 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <ShieldCheck
                className="h-3.5 w-3.5 shrink-0"
                aria-hidden="true"
              />
              <span className="min-w-0 flex-1 truncate">{poweredBy}</span>
            </a>
          )}

          <Tooltip>
            <TooltipTrigger asChild>
              <button
                type="button"
                onClick={toggleCollapsed}
                aria-keyshortcuts="Control+B Meta+B"
                aria-label={toggleLabel}
                className={railButtonClass}
              >
                {collapsed ? (
                  <PanelLeftOpen className="h-4 w-4" aria-hidden="true" />
                ) : (
                  <PanelLeftClose className="h-4 w-4" aria-hidden="true" />
                )}
              </button>
            </TooltipTrigger>
            <TooltipContent side={collapsed ? "right" : "top"}>
              {toggleLabel}
            </TooltipContent>
          </Tooltip>
        </div>
      </div>
    </aside>
  );
}

/** The square icon control of the footer, the same size as a rail row. */
const railButtonClass =
  "flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-sidebar-foreground/60 transition-colors hover:bg-sidebar-accent/60 hover:text-sidebar-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";
