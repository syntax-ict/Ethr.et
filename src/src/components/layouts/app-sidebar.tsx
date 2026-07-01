"use client";

import { SidebarNav } from "./sidebar-nav";
import { Separator } from "@/components/ui/separator";
import { TenantLogoBadge } from "@/features/branding/TenantBrandingProvider";

export function AppSidebar() {
  return (
    <aside className="hidden w-64 shrink-0 border-r border-sidebar-border bg-sidebar lg:flex lg:flex-col">
      <div className="flex h-16 items-center gap-2 border-b border-sidebar-border px-6 text-sidebar-foreground">
        <TenantLogoBadge />
      </div>
      <div className="flex-1 overflow-y-auto py-2">
        <SidebarNav />
      </div>
      <Separator />
      <div className="p-4">
        <p className="text-xs text-sidebar-foreground/50">
          Powered by ETHR · v1.0.0
        </p>
      </div>
    </aside>
  );
}
