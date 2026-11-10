import { describe, expect, it } from "vitest";
import {
  QUICK_ACTIONS,
  navSections,
  resolveHref,
  visibleDestinations,
  type NavContext,
} from "@/lib/navigation/registry";
import type { CanFlag } from "@/lib/hooks/usePermissions";

/**
 * A module the platform has Hidden leaves every list the registry feeds: the
 * sidebar, the palette's pages and its tasks (docs/FEATURE-CONTROL-CENTER-
 * DESIGN.md §3). Nothing else moves, and with nothing hidden the lists are
 * what they were.
 */

function hrAdmin(hidden: string[] = []): NavContext {
  const can = new Proxy({} as Record<CanFlag, boolean>, { get: () => true });
  return {
    can,
    isSupervisor: true,
    isTenantAdmin: true,
    isSuperAdmin: false,
    settingsLanding: "/settings",
    today: "2026-10-30",
    hidden: new Set(hidden),
  };
}

function sidebarHrefs(ctx: NavContext): string[] {
  return navSections(ctx).flatMap((section) =>
    section.entries.flatMap((entry) => {
      if (!entry.show(ctx)) return [];
      if (entry.kind === "leaf") return [resolveHref(entry.href, ctx)];
      return entry.children
        .filter((child) => child.show(ctx))
        .map((child) => resolveHref(child.href, ctx));
    }),
  );
}

const pages = (ctx: NavContext) =>
  visibleDestinations(ctx).map((d) => resolveHref(d.href, ctx));
const tasks = (ctx: NavContext) =>
  QUICK_ACTIONS.filter((a) => a.show(ctx)).map((a) => a.id);

describe("hidden features in navigation", () => {
  it("takes a hidden payroll out of the sidebar, the palette and its tasks", () => {
    const shown = hrAdmin();
    const hidden = hrAdmin(["payroll"]);

    expect(sidebarHrefs(shown)).toContain("/payroll");
    expect(sidebarHrefs(hidden)).not.toContain("/payroll");
    expect(sidebarHrefs(hidden)).not.toContain("/payroll/loans");
    expect(sidebarHrefs(hidden)).not.toContain("/payroll/payslips");

    expect(pages(hidden)).not.toContain("/settings/payroll");
    expect(tasks(shown)).toContain("run_payroll");
    expect(tasks(hidden)).not.toContain("run_payroll");
  });

  it("leaves everything else where it was", () => {
    const shown = hrAdmin();
    const hidden = hrAdmin(["payroll"]);
    const payroll = (href: string) =>
      href.startsWith("/payroll") || href === "/settings/payroll";

    expect(pages(hidden)).toEqual(pages(shown).filter((h) => !payroll(h)));
  });

  it("hides the integration pages under their own keys", () => {
    const hidden = hrAdmin(["api_access", "webhooks", "audit_log"]);

    expect(pages(hidden)).not.toContain("/settings/api-keys");
    expect(pages(hidden)).not.toContain("/settings/webhooks");
    expect(pages(hidden)).not.toContain("/settings/audit-logs");
    expect(pages(hidden)).toContain("/settings/users");
  });

  it("takes attendance and leave shortcuts with their modules", () => {
    const hidden = hrAdmin(["attendance", "leave"]);

    expect(tasks(hidden)).not.toContain("check_in");
    expect(tasks(hidden)).not.toContain("absent_today");
    expect(tasks(hidden)).not.toContain("request_leave");
    expect(sidebarHrefs(hidden)).not.toContain("/leave");
    expect(sidebarHrefs(hidden)).not.toContain("/attendance/corrections");
    expect(tasks(hidden)).toContain("add_employee");
  });
});
