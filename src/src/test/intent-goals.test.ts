import { describe, expect, it } from "vitest";
import en from "@/lib/i18n/locales/en.json";
import am from "@/lib/i18n/locales/am.json";
import type { NavContext } from "@/lib/navigation/registry";
import {
  actionRows,
  pageRows,
  rankIntents,
  type Translate,
} from "@/lib/navigation/palette-rows";
import type { CanFlag } from "@/lib/hooks/usePermissions";

/**
 * What a goal typed into the palette opens, for each kind of person, with the
 * real dictionaries. The goals are the ones people asked for in so many
 * words; a new phrase that steals one of them fails here.
 */

const TODAY = "2026-10-22";

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
    today: TODAY,
    ...rest,
  };
}

const hrAdmin = context("all", {
  isSupervisor: true,
  isTenantAdmin: true,
  settingsLanding: "/settings",
});
const supervisor = context({ viewTeam: true }, { isSupervisor: true });
const payrollOfficer = context({ processPayroll: true, viewPayrollRuns: true });
const employee = context({});

function translator(dict: Record<string, string>): Translate {
  return (key, fallback) => dict[key] ?? fallback ?? "";
}

/** Where the best rows for `query` lead: every row that shares the top score. */
function opens(
  query: string,
  ctx: NavContext,
  dict: Record<string, string> = en as Record<string, string>,
): string[] {
  const t = translator(dict);
  const ranked = rankIntents(query, [
    ...actionRows(ctx, t),
    ...pageRows(ctx, t),
  ]);
  if (ranked.length === 0) return [];
  const best = ranked[0].score;
  return ranked.filter((r) => r.score === best).map((r) => r.row.href);
}

describe("goals an HR administrator types", () => {
  it.each([
    ["Add a new employee.", "/employees/new"],
    [
      "Who is absent today?",
      `/attendance?status=absent&from=${TODAY}&to=${TODAY}`,
    ],
    ["Who is late today?", `/attendance?status=late&from=${TODAY}&to=${TODAY}`],
    ["I need to correct an attendance record.", "/attendance/corrections"],
    ["Approve a leave request.", "/approvals"],
    ["Show pending approvals.", "/approvals"],
    ["Prepare this month's payroll.", "/payroll"],
    ["Find an employee.", "/employees"],
    ["Show the attendance report.", "/reports"],
    ["Configure a new shift.", "/shifts"],
    ["I want to see my payslip.", "/payroll/payslips"],
    ["check in", "/attendance/mobile"],
    ["import employees", "/employees/import"],
    ["export employees", "/employees"],
    ["add a department", "/organization"],
    ["set up devices", "/devices"],
    ["vacancy", "/vacancies"],
    ["change my phone number", "/profile/personal"],
    ["reset my password", "/profile/security"],
    ["change my bank account", "/profile/requests"],
    ["contract expiry", "/employees/contracts"],
    ["which contracts are ending", "/employees/contracts"],
  ])("%s", (query, href) => {
    expect(opens(query, hrAdmin)).toContain(href);
  });
});

describe("goals an employee types", () => {
  it.each([
    ["I want to see my payslip.", "/payroll/payslips"],
    ["request leave", "/leave"],
    ["my leave balance", "/leave"],
    ["check in", "/attendance"],
    ["I need to correct an attendance record.", "/attendance/corrections"],
    ["change my phone number", "/profile/personal"],
    ["vacancy", "/vacancies"],
  ])("%s", (query, href) => {
    expect(opens(query, employee)).toContain(href);
  });

  it.each([
    "Add a new employee.",
    "Who is absent today?",
    "Prepare this month's payroll.",
    "Configure a new shift.",
    "import employees",
    "contract expiry",
  ])("offers nothing for %s, which is not theirs to do", (query) => {
    expect(opens(query, employee)).toEqual([]);
  });
});

describe("goals a supervisor and a payroll officer type", () => {
  it("sends a supervisor's absent and late questions to the team page", () => {
    expect(opens("Who is absent today?", supervisor)).toContain(
      "/attendance/team",
    );
    expect(opens("who is late today", supervisor)).toContain(
      "/attendance/team",
    );
    expect(opens("Approve a leave request.", supervisor)).toContain(
      "/approvals",
    );
  });

  it("opens the payroll runs list for 'prepare payroll', never a run", () => {
    expect(opens("Prepare this month's payroll.", payrollOfficer)).toEqual([
      "/payroll",
    ]);
  });
});

describe("goals that name more than one thing", () => {
  it("offers a short list of choices rather than one guess", () => {
    const t = translator(en as Record<string, string>);
    const rows = [...actionRows(supervisor, t), ...pageRows(supervisor, t)];
    const hrefs = new Set(rankIntents("leave", rows).map((r) => r.row.href));
    expect(hrefs.size).toBeGreaterThan(1);
    expect(hrefs).toContain("/leave");
  });
});

describe("goals typed in Amharic", () => {
  const amDict = am as Record<string, string>;
  it.each([
    ["ዛሬ ማን ቀረ", `/attendance?status=absent&from=${TODAY}&to=${TODAY}`],
    ["ዛሬ ማን ዘገየ", `/attendance?status=late&from=${TODAY}&to=${TODAY}`],
    ["የደሞዝ ወረቀቴ", "/payroll/payslips"],
    ["ስልክ ቁጥሬን ቀይር", "/profile/personal"],
    ["ሰራተኞችን አስመጣ", "/employees/import"],
  ])("%s", (query, href) => {
    expect(opens(query, hrAdmin, amDict)).toContain(href);
  });
});
