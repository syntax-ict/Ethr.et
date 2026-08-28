import type { ReactNode } from "react";
import { SettingsNav } from "@/components/layouts/settings-nav";

/**
 * Settings hub layout. Wraps every /settings/* page with a persistent
 * sub-navigation so configuration reads as one destination with its own left
 * nav — the modern SaaS pattern — instead of a dozen items scattered through
 * the primary sidebar. Individual pages keep their own RoleGate; this shell is
 * purely navigational.
 */
export default function SettingsLayout({ children }: { children: ReactNode }) {
  return (
    <div className="flex flex-col gap-6 lg:flex-row lg:gap-8">
      <aside className="lg:w-56 lg:shrink-0">
        <SettingsNav />
      </aside>
      <div className="min-w-0 flex-1">{children}</div>
    </div>
  );
}
