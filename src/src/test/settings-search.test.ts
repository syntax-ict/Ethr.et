import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import en from "@/lib/i18n/locales/en.json";
import am from "@/lib/i18n/locales/am.json";
import om from "@/lib/i18n/locales/om.json";
import {
  type NavContext,
  phrasesKey,
  resolveHref,
  SETTINGS_DESTINATIONS,
  SETTINGS_GROUPS,
  SETTINGS_TABS,
  settingsLandingFor,
  visibleDestinations,
} from "@/lib/navigation/registry";
import {
  pageRows,
  rankIntents,
  type Translate,
} from "@/lib/navigation/palette-rows";
import { ROUTE_META, routeI18nKey } from "@/lib/route-meta";
import type { CanFlag } from "@/lib/hooks/usePermissions";

/**
 * Settings in the command palette (settings review 2026-11-22, F9): typing
 * "SSO", "webhooks", "tax", "holidays" or "password" finds the page or tab,
 * in each language, for the people who may open it and nobody else.
 */

const dictionaries = {
  en: en as Record<string, string>,
  am: am as Record<string, string>,
  om: om as Record<string, string>,
};

function context(
  flags: Partial<Record<CanFlag, boolean>> | "all",
  rest: Partial<Omit<NavContext, "can">> = {},
): NavContext {
  const can = new Proxy({} as Record<CanFlag, boolean>, {
    get: (_, flag: string) =>
      flags === "all" ? true : (flags[flag as CanFlag] ?? false),
  });
  return {
    can,
    isSupervisor: false,
    isTenantAdmin: false,
    isSuperAdmin: false,
    settingsLanding: null,
    today: "2026-11-23",
    ...rest,
  };
}

const tenantAdmin = context("all", {
  isSupervisor: true,
  isTenantAdmin: true,
  settingsLanding: "/settings",
});
// Holds the abilities an HR admin has, and not settings.manage.
const hrFlags: Partial<Record<CanFlag, boolean>> = {
  manageHolidays: true,
  manageLeaveTypes: true,
  manageAttendance: true,
  manageShifts: true,
  viewUsers: true,
};
const hrAdmin = context(hrFlags, {
  isSupervisor: true,
  settingsLanding: settingsLandingFor({
    can: context(hrFlags).can,
    isSuperAdmin: false,
  }),
});
const financeAdmin = context({
  viewPayrollConfig: true,
  viewPayrollRuns: true,
});

function translator(locale: keyof typeof dictionaries): Translate {
  return (key, fallback) => dictionaries[locale][key] ?? fallback ?? "";
}

/** Every settings row that answers `query`, best first, as `id -> href`. */
function found(
  query: string,
  ctx: NavContext,
  locale: keyof typeof dictionaries = "en",
): Array<{ id: string; href: string }> {
  const settingsIds = new Set(SETTINGS_DESTINATIONS.map((d) => d.id));
  return rankIntents(query, pageRows(ctx, translator(locale)))
    .map((r) => r.row)
    .filter((row) => settingsIds.has(row.id))
    .map((row) => ({ id: row.id, href: row.href }));
}

describe("searching settings from the palette", () => {
  it("finds Single sign-on for 'sso', and by its longer names", () => {
    for (const query of ["sso", "single sign-on", "saml"]) {
      expect(found(query, tenantAdmin)[0], query).toEqual({
        id: "settings_sso",
        href: "/settings?tab=sso",
      });
    }
  });

  it("finds Payroll rules for 'tax'", () => {
    expect(found("tax", financeAdmin)[0]).toEqual({
      id: "payroll_rules",
      href: "/settings/payroll",
    });
    expect(found("income tax pension", financeAdmin)[0]?.id).toBe(
      "payroll_rules",
    );
  });

  it("finds the Security tab for 'password', 'sign-in' and 'activation code'", () => {
    for (const query of ["password", "sign-in", "activation code"]) {
      expect(found(query, tenantAdmin)[0], query).toEqual({
        id: "settings_security",
        href: "/settings?tab=security",
      });
    }
  });

  it("finds the pages the review named: webhooks and holidays", () => {
    expect(found("webhooks", tenantAdmin)[0]).toEqual({
      id: "webhooks",
      href: "/settings/webhooks",
    });
    expect(found("public holidays calendar", tenantAdmin)[0]?.id).toBe(
      "holidays",
    );
  });

  it("finds each tab of Settings > General at its ?tab= address", () => {
    expect(found("general settings", tenantAdmin)[0]).toEqual({
      id: "settings_general",
      href: "/settings?tab=general",
    });
    expect(found("logo", tenantAdmin)[0]).toEqual({
      id: "settings_branding",
      href: "/settings?tab=branding",
    });
  });

  it("finds the pages the palette did not list at all", () => {
    expect(found("scim", tenantAdmin)[0]?.id).toBe("scim");
    expect(found("email templates", tenantAdmin)[0]?.id).toBe(
      "notification_templates",
    );
    expect(found("chart of accounts", tenantAdmin)[0]?.id).toBe("accounting");
    expect(found("opening balances", tenantAdmin)[0]?.id).toBe(
      "opening_balances",
    );
  });

  it("files every settings row under Settings, so 'settings' lists them", () => {
    const rows = pageRows(tenantAdmin, translator("en")).filter((r) =>
      SETTINGS_DESTINATIONS.some((d) => d.id === r.id),
    );
    // Shift Rules is /shifts, which the sidebar already lists as Shifts; one
    // row per address, the sidebar's first.
    expect(rows.map((r) => r.id).sort()).toEqual(
      SETTINGS_DESTINATIONS.map((d) => d.id)
        .filter((id) => id !== "shift_rules")
        .sort(),
    );
    for (const row of rows) expect(row.context, row.id).toBe("Settings");
    const hits = found("settings holidays", tenantAdmin);
    expect(hits[0]?.id).toBe("holidays");
  });
});

describe("who is offered what", () => {
  it("shows an HR admin the pages they hold and not SSO, webhooks or roles", () => {
    expect(found("holidays", hrAdmin)[0]?.id).toBe("holidays");
    expect(found("leave types", hrAdmin)[0]?.id).toBe("leave_types");
    expect(found("users", hrAdmin)[0]?.id).toBe("users");
    // The sidebar's Settings row leads to /settings/attendance for them; it
    // does not take the address from the Attendance Rules row.
    expect(hrAdmin.settingsLanding).toBe("/settings/attendance");
    expect(found("attendance rules", hrAdmin)[0]?.id).toBe("attendance_rules");
    expect(found("sso", hrAdmin)).toEqual([]);
    expect(found("password", hrAdmin)).toEqual([]);
    expect(found("webhooks", hrAdmin)).toEqual([]);
    expect(found("roles", hrAdmin)).toEqual([]);
    expect(found("tax", hrAdmin)).toEqual([]);
  });

  it("offers a finance admin payroll rules and accounting, and no tab", () => {
    const ids = visibleDestinations(financeAdmin).map((d) => d.id);
    expect(ids).toContain("payroll_rules");
    expect(ids).toContain("accounting");
    expect(ids).not.toContain("settings_security");
    expect(ids).not.toContain("settings_sso");
  });

  it("offers an employee no settings row at all", () => {
    const ids = new Set(visibleDestinations(context({})).map((d) => d.id));
    for (const d of SETTINGS_DESTINATIONS)
      expect(ids.has(d.id), d.id).toBe(false);
  });

  it("offers a platform account none, even one holding settings.manage", () => {
    const platform = context("all", { isSuperAdmin: true });
    const ids = new Set(visibleDestinations(platform).map((d) => d.id));
    for (const d of SETTINGS_DESTINATIONS)
      expect(ids.has(d.id), d.id).toBe(false);
  });

  it("gives opening balances to either ability its page accepts", () => {
    const ids = (flags: Partial<Record<CanFlag, boolean>>) =>
      visibleDestinations(context(flags)).map((d) => d.id);
    expect(ids({ adjustLeaveBalance: true })).toContain("opening_balances");
    expect(ids({ manageLoans: true })).toContain("opening_balances");
    expect(ids({ viewPayrollRuns: true })).not.toContain("opening_balances");
  });
});

describe("other languages", () => {
  it("finds Single sign-on in Amharic", () => {
    expect(found("ነጠላ መግቢያ", tenantAdmin, "am")[0]?.id).toBe("settings_sso");
  });

  it("finds Payroll rules and the Security tab in Amharic", () => {
    expect(found("የገቢ ግብር", financeAdmin, "am")[0]?.id).toBe("payroll_rules");
    expect(found("የይለፍ ቃል", tenantAdmin, "am")[0]?.id).toBe(
      "settings_security",
    );
  });

  it("finds Single sign-on and Payroll rules in Afaan Oromoo", () => {
    expect(found("seensa tokkoo", tenantAdmin, "om")[0]?.id).toBe(
      "settings_sso",
    );
    expect(found("gibira galii", financeAdmin, "om")[0]?.id).toBe(
      "payroll_rules",
    );
  });

  it("does not find a page in a language whose words it lacks", () => {
    // The English word "sso" is a phrase of the SSO row in every language, but
    // an Amharic word for taxes does not find webhooks.
    expect(found("የገቢ ግብር", tenantAdmin, "am").map((r) => r.id)).not.toContain(
      "webhooks",
    );
  });
});

describe("the one list", () => {
  it("has a label, phrases and a route description in en, am and om for every row", () => {
    const gaps: string[] = [];
    for (const d of SETTINGS_DESTINATIONS) {
      for (const [locale, dict] of Object.entries(dictionaries)) {
        for (const key of [d.labelKey, phrasesKey(d.id)]) {
          if (dict[key] === undefined) gaps.push(`${key} (${locale})`);
        }
        const path = d.href.split("?")[0];
        if (ROUTE_META[path] === undefined) {
          gaps.push(`${d.id}: ${path} is not in ROUTE_META`);
        } else if (dict[routeI18nKey(path, "desc")] === undefined) {
          gaps.push(`${routeI18nKey(path, "desc")} (${locale})`);
        }
      }
    }
    expect(gaps).toEqual([]);
  });

  it("uses each id and each address once", () => {
    const ids = SETTINGS_DESTINATIONS.map((d) => d.id);
    expect(new Set(ids).size).toBe(ids.length);
    const hrefs = SETTINGS_DESTINATIONS.map((d) => d.href);
    expect(new Set(hrefs).size).toBe(hrefs.length);
  });

  it("lists every settings page the app has, once, in the settings navigation", () => {
    const navHrefs = SETTINGS_GROUPS.flatMap((g) => g.items).map(
      (i) => i.navHref ?? i.href,
    );
    expect(navHrefs).toEqual([
      "/settings",
      "/settings/attendance",
      "/shifts",
      "/settings/leave-types",
      "/settings/holidays",
      "/settings/approval-chains",
      "/settings/exit-clearance",
      "/settings/payroll",
      "/settings/opening-balances",
      "/settings/users",
      "/settings/roles",
      "/settings/api-keys",
      "/settings/webhooks",
      "/settings/accounting",
      "/settings/notification-templates",
      "/settings/scim",
      "/settings/audit-logs",
      "/settings/data-export",
    ]);
  });

  it("has a row for every tab the General page reads from ?tab=", () => {
    const source = readFileSync(
      resolve(__dirname, "../app/(dashboard)/settings/page.tsx"),
      "utf8",
    );
    const declared = /VALID_TABS = \[([^\]]*)\]/.exec(source)?.[1];
    expect(declared, "VALID_TABS not found in settings/page.tsx").toBeDefined();
    const tabs = [...declared!.matchAll(/"([a-z_]+)"/g)].map((m) => m[1]);
    const rows = [SETTINGS_GROUPS[0].items[0], ...SETTINGS_TABS].map(
      (d) => d.href,
    );
    expect(rows).toEqual(tabs.map((tab) => `/settings?tab=${tab}`));
  });

  it("opens the first settings page a person holds, one inside the hub first", () => {
    const access = (flags: Partial<Record<CanFlag, boolean>>) => {
      const ctx = context(flags);
      return settingsLandingFor({ can: ctx.can, isSuperAdmin: false });
    };
    expect(access({ manageSettings: true })).toBe("/settings");
    // Shift Rules is outside the hub, so it does not lead.
    expect(access({ manageShifts: true, manageLeaveTypes: true })).toBe(
      "/settings/leave-types",
    );
    expect(access({ manageShifts: true })).toBe("/shifts");
    expect(access({})).toBeNull();
  });

  it("resolves every row's address to a string", () => {
    for (const d of SETTINGS_DESTINATIONS) {
      expect(resolveHref(d.href, tenantAdmin)).toMatch(/^\//);
    }
  });
});
