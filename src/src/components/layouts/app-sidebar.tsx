"use client";

import { Command } from "lucide-react";
import { SidebarNav } from "./sidebar-nav";
import { Separator } from "@/components/ui/separator";
import { TenantLogoBadge } from "@/features/branding/TenantBrandingProvider";

export function AppSidebar() {
  return (
    <aside
      aria-label="Main navigation"
      className="hidden w-64 shrink-0 border-r border-sidebar-border bg-sidebar lg:flex lg:flex-col"
    >
      <div className="flex h-16 items-center gap-2 border-b border-sidebar-border px-6 text-sidebar-foreground">
        <TenantLogoBadge />
      </div>
      <div className="flex-1 overflow-y-auto py-2">
        <SidebarNav />
      </div>
      <Separator />
      <div className="p-4">
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
          <span>Command Palette</span>
          <kbd className="ml-auto rounded border border-sidebar-border/50 px-1.5 py-0.5 font-mono text-[10px]">
            ⌘K
          </kbd>
        </button>
        <p className="mt-2 text-xs text-sidebar-foreground/50">
          Powered by ETHR · v1.0.0
        </p>
      </div>
    </aside>
  );
}
