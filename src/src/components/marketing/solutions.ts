/**
 * The search-intent pages (2026-10-19): one per thing people search for when
 * they look for HR software in Ethiopia, all rendered by `SolutionPage`.
 *
 * Each point is a capability the code has, checked against the 2026-10-17
 * capability audit: no device brands, no biometric-device claims, no
 * multi-level approvals, no performance or training modules. A point that is
 * not true of the product does not belong here, however well it would rank.
 *
 * Copy lives in the dictionaries under `marketing.solutions.{id}.*`, and the
 * head under `marketing.meta.{id}.*`, so each page's title, H1 and points are
 * translated like every other public page.
 */
export const SOLUTIONS = [
  {
    id: "payroll_software",
    path: "/payroll-software",
    points: ["tax", "pension", "deductions", "payslips", "explained"],
  },
  {
    id: "attendance_system",
    path: "/attendance-system",
    points: ["methods", "location", "offline", "shifts", "integrity"],
  },
  {
    id: "leave_management",
    path: "/leave-management",
    points: ["types", "balances", "requests", "holidays"],
  },
  {
    id: "ethiopian_calendar_hr",
    path: "/ethiopian-calendar-hr",
    points: ["dual", "pagumen", "holidays", "bilingual"],
  },
] as const;

export type SolutionId = (typeof SOLUTIONS)[number]["id"];

export function solutionFor(id: SolutionId) {
  return SOLUTIONS.find((s) => s.id === id)!;
}
