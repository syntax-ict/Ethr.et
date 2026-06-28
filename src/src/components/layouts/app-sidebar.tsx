'use client';

import { SidebarNav } from './sidebar-nav';
import { Separator } from '@/components/ui/separator';

export function AppSidebar() {
  return (
    <aside className="hidden w-64 shrink-0 border-r border-sidebar-border bg-sidebar lg:flex lg:flex-col">
      <div className="flex h-16 items-center gap-2 border-b border-sidebar-border px-6">
        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary">
          <span className="text-sm font-bold text-primary-foreground">E</span>
        </div>
        <span className="text-lg font-bold tracking-tight text-sidebar-foreground">ETHR</span>
      </div>
      <div className="flex-1 overflow-y-auto py-2">
        <SidebarNav />
      </div>
      <Separator />
      <div className="p-4">
        <p className="text-xs text-sidebar-foreground/50">ETHR v1.0.0</p>
      </div>
    </aside>
  );
}
