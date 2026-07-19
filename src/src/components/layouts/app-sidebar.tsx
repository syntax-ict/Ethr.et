"use client";

import { Command, PanelLeftClose, PanelLeftOpen } from "lucide-react";
import { SidebarNav } from "./sidebar-nav";
import { Separator } from "@/components/ui/separator";
import { TenantLogoBadge } from "@/features/branding/TenantBrandingProvider";
import { useAppLayout } from "./layout-context";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

export function AppSidebar() {
  const { collapsed, toggleCollapsed } = useAppLayout();
  const { t } = useT();

  return (
    <aside
      aria-label="Main navigation"
      data-collapsed={collapsed}
      className={cn(
        "hidden shrink-0 border-r border-sidebar-border bg-sidebar text-sidebar-foreground transition-[width] duration-200 lg:flex lg:flex-col",
        collapsed ? "w-16" : "w-64",
      )}
    >
      <div
        className={cn(
          "flex h-16 items-center border-b border-sidebar-border",
          collapsed ? "justify-center px-2" : "gap-2 px-6",
        )}
      >
        {!collapsed && <TenantLogoBadge />}
        {collapsed && (
          <div className="flex h-8 w-8 items-center justify-center rounded-md bg-sidebar-accent text-sm font-bold text-sidebar-accent-foreground">
            E
          </div>
        )}
      </div>

      <div className="flex-1 overflow-y-auto overflow-x-hidden py-2">
        <SidebarNav collapsed={collapsed} />
      </div>

      <Separator className="bg-sidebar-border" />
      <div className={cn("p-2", collapsed && "flex flex-col items-center")}>
        {!collapsed && (
          <button
            type="button"
            onClick={() =>
              document.dispatchEvent(
                new KeyboardEvent("keydown", { key: "k", metaKey: true }),
              )
            }
            className="flex w-full items-center gap-2 rounded-md border border-sidebar-border/50 px-3 py-1.5 text-xs text-sidebar-foreground/60 transition-colors hover:bg-sidebar-accent/30 hover:text-sidebar-foreground"
          >
            <Command className="h-3 w-3" />
            <span>{t("sidebar.command_palette", "Command Palette")}</span>
            <kbd className="ml-auto rounded border border-sidebar-border/50 px-1.5 py-0.5 font-mono text-[10px]">
              ⌘K
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
            collapsed ? "mt-0 w-10 justify-center" : "mt-2 w-full",
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
              <kbd className="rounded border border-sidebar-border/50 px-1 text-[10px]">
                ⌘B
              </kbd>
            </>
          )}
        </button>

        {!collapsed && (
          <p className="mt-2 px-2 text-xs text-sidebar-foreground/50">
            Powered by ETHR
          </p>
        )}
      </div>
    </aside>
  );
}
