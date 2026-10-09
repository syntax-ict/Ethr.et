import type { useT } from "@/lib/i18n/useT";
import { formatETB } from "@/lib/utils/currency";

type T = ReturnType<typeof useT>["t"];

/**
 * Display names for the fields `ReportEngine::SOURCES` offers. The builder
 * listed them by API key — "salary_cents", "employee_code" — in the column
 * picker, the filter and sort selects and the results header (audit N30).
 *
 * Each label is a literal `t()` call so the i18n gate can check both locales.
 */
const LABELS: Record<string, (t: T) => string> = {
  name: (t) => t("reports_page.fields.name", "Name"),
  email: (t) => t("reports_page.fields.email", "Email"),
  phone: (t) => t("reports_page.fields.phone", "Phone"),
  employee_code: (t) => t("reports_page.fields.employee_code", "Employee code"),
  gender: (t) => t("reports_page.fields.gender", "Gender"),
  status: (t) => t("reports_page.fields.status", "Status"),
  hire_date: (t) => t("reports_page.fields.hire_date", "Hire date"),
  salary_cents: (t) => t("reports_page.fields.salary", "Salary"),
  department: (t) => t("reports_page.fields.department", "Department"),
  branch: (t) => t("reports_page.fields.branch", "Branch"),
  position: (t) => t("reports_page.fields.position", "Position"),
  employee_name: (t) => t("reports_page.fields.employee_name", "Employee"),
  date: (t) => t("reports_page.fields.date", "Date"),
  check_in: (t) => t("reports_page.fields.check_in", "Check-in"),
  check_out: (t) => t("reports_page.fields.check_out", "Check-out"),
  source: (t) => t("reports_page.fields.source", "Source"),
  worked_minutes: (t) =>
    t("reports_page.fields.worked_minutes", "Worked (minutes)"),
  leave_type: (t) => t("reports_page.fields.leave_type", "Leave type"),
  year: (t) => t("reports_page.fields.year", "Year"),
  entitled_days: (t) => t("reports_page.fields.entitled_days", "Entitled days"),
  used_days: (t) => t("reports_page.fields.used_days", "Used days"),
  remaining_days: (t) =>
    t("reports_page.fields.remaining_days", "Remaining days"),
  period: (t) => t("reports_page.fields.period", "Period"),
  // Filter-only keys (ReportEngine applies them; no column carries them).
  from: (t) => t("reports_page.fields.from", "From"),
  to: (t) => t("reports_page.fields.to", "To"),
  basic_salary_cents: (t) =>
    t("reports_page.fields.basic_salary", "Basic salary"),
  gross_cents: (t) => t("reports_page.fields.gross", "Gross pay"),
  income_tax_cents: (t) => t("reports_page.fields.income_tax", "Income tax"),
  employee_pension_cents: (t) =>
    t("reports_page.fields.employee_pension", "Employee pension"),
  net_cents: (t) => t("reports_page.fields.net", "Net pay"),
};

/** The field's display name; an unknown field keeps a readable form of its key. */
export function reportFieldLabel(t: T, field: string): string {
  const label = LABELS[field];
  if (label) return label(t);
  const words = field.replace(/_cents$/, "").replace(/_/g, " ");
  return words.charAt(0).toUpperCase() + words.slice(1);
}

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;

/**
 * A result cell as text. `*_cents` fields are money and shown in ETB
 * (convention 3) — the table printed "500000" under "salary (¢)". A date-only
 * value goes through `formatDate` when given, so a hire date reads in the
 * user's calendar like every other date in the app, not as raw ISO.
 */
export function formatReportCell(
  field: string,
  value: unknown,
  formatDate?: (iso: string) => string,
): string {
  if (value == null || value === "") return "—";
  if (field.endsWith("_cents")) {
    const cents = typeof value === "number" ? value : Number(value);
    if (Number.isFinite(cents)) return formatETB(cents);
  }
  if (formatDate && typeof value === "string" && DATE_ONLY.test(value)) {
    return formatDate(value);
  }
  return String(value);
}
