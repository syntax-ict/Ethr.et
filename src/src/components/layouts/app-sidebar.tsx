"use client";

import { useRef, useCallback, useEffect, useState } from "react";
import Link from "next/link";
import {
  ChevronsUpDown,
  Command,
  CreditCard,
  LogOut,
  PanelLeftClose,
  PanelLeftOpen,
  Settings,
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
import { useAppLayout } from "./layout-context";
import {
  useCurrentUser,
  useCurrentTenant,
  useLogout,
} from "@/features/auth/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useShortcutLabel } from "@/lib/hooks/usePlatformShortcut";
import { openCommandPalette } from "@/components/shared/command-palette";
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
            <TenantLogoBadge showName={false} />
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
  const paletteShortcut = useShortcutLabel("K");
  const collapseShortcut = useShortcutLabel("B");

  const roleName = user?.role?.replace(/_/g, " ") ?? "";
  const tenantName = tenant?.name ?? "ETHR";
  const tenantInitial = tenantName.charAt(0).toUpperCase();

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
              aria-label={t("nav.workspace_menu", "Workspace menu")}
              className="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 transition-colors hover:bg-sidebar-accent/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring data-[state=open]:bg-sidebar-accent/30"
            >
              <TenantLogoBadge showName={false} />
              <div className="min-w-0 flex-1 text-left">
                <p className="truncate text-sm font-semibold text-sidebar-foreground">
                  {tenantName}
                </p>
                {roleName && (
                  <p className="truncate text-[11px] capitalize text-sidebar-foreground/70">
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
              aria-label={t("nav.workspace_menu", "Workspace menu")}
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

      {/* Footer — shrink-0 keeps it pinned at the bottom */}
      <div className="shrink-0 border-t border-sidebar-border">
        {/* Ethiopian tibeb pattern accent — the design system's signature
            element (CLAUDE.md, Visual Identity).

            Alpha comes from `color-mix`, not `var(--x)/0.15`. The slash-alpha
            shorthand is only valid on a bare colour token, not on a `var()`
            reference: the previous markup compiled to
            `var(--sidebar-foreground)/.15 0px`, which is invalid CSS, so every
            browser discarded the whole gradient and the pattern never rendered
            at all. `color-mix` is the same technique globals.css already uses
            for `::selection`. */}
        <div
          aria-hidden="true"
          className="h-0.5 w-full bg-[repeating-linear-gradient(90deg,color-mix(in_srgb,var(--sidebar-foreground)_15%,transparent)_0px,color-mix(in_srgb,var(--sidebar-foreground)_15%,transparent)_6px,color-mix(in_srgb,var(--brand-accent)_35%,transparent)_6px,color-mix(in_srgb,var(--brand-accent)_35%,transparent)_12px,color-mix(in_srgb,var(--sidebar-foreground)_15%,transparent)_12px,color-mix(in_srgb,var(--sidebar-foreground)_15%,transparent)_18px,transparent_18px,transparent_24px)]"
        />

        <div className={cn("p-2", collapsed && "flex flex-col items-center")}>
          {!collapsed && (
            <button
              type="button"
              // `openCommandPalette()`, not a synthesised KeyboardEvent. The
              // palette exports this exact function and its own source calls
              // faking a keypress out as the thing to avoid; the synthetic
              // event also depended on the listener living on `document` and
              // on `metaKey` specifically.
              onClick={openCommandPalette}
              className="flex w-full items-center gap-2 rounded-lg border border-sidebar-border/50 px-3 py-2 text-xs text-sidebar-foreground/60 transition-colors hover:bg-sidebar-accent/30 hover:text-sidebar-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <Command className="h-3.5 w-3.5" aria-hidden="true" />
              <span>{t("sidebar.command_palette", "Command Palette")}</span>
              <kbd className="ml-auto rounded border border-sidebar-border/50 px-1.5 py-0.5 font-mono text-[10px] leading-none">
                {paletteShortcut}
              </kbd>
            </button>
          )}

          <button
            type="button"
            onClick={toggleCollapsed}
            title={
              collapsed
                ? t("sidebar.expand", "Expand sidebar")
                : t("sidebar.collapse", "Collapse sidebar")
            }
            className={cn(
              "flex items-center gap-2 rounded-lg px-2 py-2 text-xs font-medium text-sidebar-foreground/60 transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
              collapsed ? "mt-0 w-10 justify-center" : "mt-1.5 w-full",
            )}
          >
            {collapsed ? (
              <PanelLeftOpen className="h-4 w-4" />
            ) : (
              <>
                <PanelLeftClose className="h-4 w-4" />
                <span className="flex-1 text-left">
                  {t("sidebar.collapse", "Collapse")}
                </span>
                <kbd className="rounded border border-sidebar-border/50 px-1 text-[10px] leading-none">
                  {collapseShortcut}
                </kbd>
              </>
            )}
          </button>
        </div>
      </div>
    </aside>
  );
}
