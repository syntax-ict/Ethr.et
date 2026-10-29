import { describe, expect, it } from "vitest";
import en from "@/lib/i18n/locales/en.json";
import am from "@/lib/i18n/locales/am.json";
import {
  MORE_DESTINATIONS,
  type NavContext,
  navSections,
  phrasesKey,
  QUICK_ACTIONS,
  resolveHref,
  sidebarSectionOf,
  visibleDestinations,
} from "@/lib/navigation/registry";
import { ROUTE_META, routeI18nKey, sectionI18nKey } from "@/lib/route-meta";
import type { CanFlag } from "@/lib/hooks/usePermissions";

const enDict = en as Record<string, string>;
const amDict = am as Record<string, string>;

function context(
  flags: Partial<Record<CanFlag, boolean>> = {},
  rest: Partial<Omit<NavContext, "can">> = {},
): NavContext {
  const can = new Proxy({} as Record<CanFlag, boolean>, {
    get: (_, flag: string) => flags[flag as CanFlag] ?? false,
  });
  return {
    can,
    isSupervisor: false,
    isTenantAdmin: false,
    isSuperAdmin: false,
    settingsLanding: null,
    today: "2026-10-21",
    ...rest,
  };
}

const everything = context(
  new Proxy({} as Record<CanFlag, boolean>, { get: () => true }),
  { isSupervisor: true, isTenantAdmin: true, settingsLanding: "/settings" },
);

function allDestinations() {
  const out = [];
  for (const section of navSections(everything)) {
    for (const entry of section.entries) {
      if (entry.kind === "leaf") out.push(entry);
      else out.push(...entry.children);
    }
  }
  out.push(...MORE_DESTINATIONS);
  return out;
}

/**
 * The registry's keys are read through variables, so the i18n gate's scan of
 * `t("literal")` calls never sees them: this is where a missing one fails.
 */
describe("navigation registry dictionary", () => {
  it("has a label, phrases and a description in both languages for every destination", () => {
    const gaps: string[] = [];
    for (const d of allDestinations()) {
      for (const key of [d.labelKey, phrasesKey(d.id)]) {
        if (enDict[key] === undefined) gaps.push(`${key} (en)`);
        if (amDict[key] === undefined) gaps.push(`${key} (am)`);
      }
      const path = resolveHref(d.href, everything).split("?")[0];
      if (ROUTE_META[path] === undefined) {
        gaps.push(`${d.id}: ${path} is not in ROUTE_META`);
        continue;
      }
      const desc = routeI18nKey(path, "desc");
      if (enDict[desc] === undefined) gaps.push(`${desc} (en)`);
      if (amDict[desc] === undefined) gaps.push(`${desc} (am)`);
    }
    expect(gaps).toEqual([]);
  });

  it("has a label, phrases and a description in both languages for every quick action", () => {
    const gaps: string[] = [];
    for (const a of QUICK_ACTIONS) {
      for (const key of [a.labelKey, a.descKey, phrasesKey(a.id)]) {
        if (enDict[key] === undefined) gaps.push(`${key} (en)`);
        if (amDict[key] === undefined) gaps.push(`${key} (am)`);
      }
      const path = resolveHref(a.href, everything).split("?")[0];
      if (ROUTE_META[path] === undefined) {
        gaps.push(`${a.id}: ${path} is not in ROUTE_META`);
      }
    }
    expect(gaps).toEqual([]);
  });

  it("keeps every phrase list free of the characters the house style bans", () => {
    for (const [key, value] of Object.entries(enDict)) {
      if (!key.startsWith("intent.")) continue;
      expect(value, key).not.toMatch(/[—!]/);
      expect(amDict[key], key).not.toMatch(/[—!]/);
    }
  });

  it("uses each destination id once", () => {
    const ids = allDestinations().map((d) => d.id);
    expect(new Set(ids).size).toBe(ids.length);
    const actionIds = QUICK_ACTIONS.map((a) => a.id);
    expect(new Set(actionIds).size).toBe(actionIds.length);
  });
});

describe("what each person may open", () => {
  it("offers an employee their own pages and no administration", () => {
    const hrefs = visibleDestinations(context()).map((d) =>
      resolveHref(d.href, context()),
    );
    // Workspace, then People, then Communication; the palette-only pages last.
    expect(hrefs).toEqual([
      "/dashboard",
      "/attendance",
      "/leave",
      "/payroll/payslips",
      "/vacancies",
      "/directory",
      "/announcements",
      "/voice",
      "/profile",
      "/notifications",
    ]);
    // Corrections is under the Attendance group, which this person cannot
    // open at all: the row is not offered on its own either.
    expect(hrefs).not.toContain("/attendance/corrections");
  });

  it("offers a supervisor approvals, the team's attendance and corrections", () => {
    const ctx = context({ viewTeam: true }, { isSupervisor: true });
    const hrefs = visibleDestinations(ctx).map((d) => resolveHref(d.href, ctx));
    expect(hrefs).toContain("/approvals");
    expect(hrefs).toContain("/attendance/team");
    expect(hrefs).toContain("/attendance/corrections");
    expect(hrefs).not.toContain("/payroll");
    expect(hrefs).not.toContain("/employees");
  });

  it("names the group a child belongs to", () => {
    const ctx = context({ viewAllAttendance: true });
    const records = visibleDestinations(ctx).find(
      (d) => d.id === "attendance_records",
    );
    expect(records?.context?.labelFallback).toBe("Attendance");
  });

  it("lists one row per address, the sidebar's first", () => {
    const hrefs = visibleDestinations(everything).map((d) =>
      resolveHref(d.href, everything),
    );
    expect(new Set(hrefs).size).toBe(hrefs.length);
  });

  it("offers a platform super admin the console and nothing of a tenant", () => {
    const ctx = context(
      { viewAdminConsole: true },
      { isSuperAdmin: true, isTenantAdmin: true, isSupervisor: true },
    );
    const hrefs = visibleDestinations(ctx).map((d) => resolveHref(d.href, ctx));
    expect(hrefs).toEqual([
      "/admin",
      "/admin/operations",
      "/admin/tenants",
      "/admin/audit",
      "/admin/platform-settings",
      "/admin/sms",
      "/admin/plans",
      "/admin/marketing",
    ]);
    expect(QUICK_ACTIONS.filter((a) => a.show(ctx))).toEqual([]);
  });

  it("sends 'who is absent today' where the person may look", () => {
    const absent = QUICK_ACTIONS.find((a) => a.id === "absent_today")!;
    const hr = context({ viewAllAttendance: true });
    expect(resolveHref(absent.href, hr)).toBe(
      "/attendance?status=absent&from=2026-10-21&to=2026-10-21",
    );
    const supervisor = context({ viewTeam: true }, { isSupervisor: true });
    expect(resolveHref(absent.href, supervisor)).toBe("/attendance/team");
    expect(absent.show(context())).toBe(false);
  });

  it("offers payroll only to someone who may process it, and only opens the list", () => {
    const run = QUICK_ACTIONS.find((a) => a.id === "run_payroll")!;
    expect(run.show(context({ viewPayrollRuns: true }))).toBe(false);
    expect(run.show(context({ processPayroll: true }))).toBe(true);
    expect(resolveHref(run.href, everything)).toBe("/payroll");
  });
});

/**
 * Production QA 2026-10-28, F-03: the breadcrumb named Reports "Finance" and
 * Announcements "My Work" while the sidebar said Insights and Communication.
 */
describe("breadcrumb sections", () => {
  const sidebarSections = [
    ...navSections(context()),
    ...navSections(context({}, { isSuperAdmin: true })),
  ];

  it("names only sections the sidebar has, by the sidebar's own key", () => {
    const keys = new Map(
      sidebarSections.map((s) => [s.titleFallback, s.titleKey]),
    );
    const strays = Object.entries(ROUTE_META)
      .filter(([, meta]) => meta.section !== undefined)
      .filter(
        ([, meta]) => keys.get(meta.section!) !== sectionI18nKey(meta.section!),
      )
      .map(([path, meta]) => `${path}: ${meta.section}`);
    expect(strays).toEqual([]);
  });

  it("agrees with the sidebar for every page the sidebar lists", () => {
    const disagreements: string[] = [];
    for (const ctx of [context(), everything]) {
      for (const d of visibleDestinations(ctx)) {
        const path = resolveHref(d.href, ctx).split("?")[0];
        const stated = ROUTE_META[path]?.section;
        const section = sidebarSectionOf(path, ctx);
        if (stated === undefined || section === null) continue;
        // /attendance is the one address the sidebar places by role.
        if (path === "/attendance") continue;
        if (section.titleFallback !== stated) {
          disagreements.push(`${path}: ${stated} vs ${section.titleFallback}`);
        }
      }
    }
    expect(disagreements).toEqual([]);
  });

  it("places /attendance where this person's sidebar does", () => {
    expect(sidebarSectionOf("/attendance", context())?.titleKey).toBe(
      "nav.section.workspace",
    );
    expect(
      sidebarSectionOf("/attendance", context({ viewAllAttendance: true }))
        ?.titleKey,
    ).toBe("nav.section.operations");
  });

  it("reads a static-export path with its trailing slash", () => {
    expect(
      sidebarSectionOf("/reports/", context({ viewReports: true }))?.titleKey,
    ).toBe("nav.section.insights");
  });

  it("finds nothing for a page with no sidebar row, or one the person cannot see", () => {
    expect(sidebarSectionOf("/attendance/kiosks", everything)).toBeNull();
    expect(sidebarSectionOf("/reports", context())).toBeNull();
  });
});
