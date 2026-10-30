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
  grade: (t) => t("reports_page.fields.grade", "Grade"),
  cost_center: (t) => t("reports_page.fields.cost_center", "Cost centre"),
  overtime_minutes: (t) =>
    t("reports_page.fields.overtime_minutes", "Overtime (minutes)"),
  work_days: (t) => t("reports_page.fields.work_days", "Workdays"),
  present_days: (t) => t("reports_page.fields.present_days", "Present"),
  late_days: (t) => t("reports_page.fields.late_days", "Late"),
  absent_days: (t) => t("reports_page.fields.absent_days", "Absent"),
  leave_days: (t) => t("reports_page.fields.leave_days", "On leave"),
  holiday_days: (t) => t("reports_page.fields.holiday_days", "Holidays"),
  worked_hours: (t) => t("reports_page.fields.worked_hours", "Worked hours"),
  overtime_hours: (t) =>
    t("reports_page.fields.overtime_hours", "Overtime hours"),
  overtime_cents: (t) => t("reports_page.fields.overtime_pay", "Overtime pay"),
  allowances_cents: (t) => t("reports_page.fields.allowances", "Allowances"),
  employer_pension_cents: (t) =>
    t("reports_page.fields.employer_pension", "Employer pension"),
  loan_cents: (t) => t("reports_page.fields.loan", "Loan repayment"),
  cost_sharing_cents: (t) =>
    t("reports_page.fields.cost_sharing", "Cost sharing"),
  // Filter-only key, as `from` and `to` above.
  month: (t) => t("reports_page.fields.month", "Month"),
};

/**
 * Display names for the report sources. The builder showed the API's English
 * `label` ("Employees") and the results showed the raw key ("timesheet") on
 * an Amharic screen. Literal `t()` calls so the i18n gate checks both locales.
 */
const SOURCE_LABELS: Record<string, (t: T) => string> = {
  employees: (t) => t("reports_page.sources.employees", "Employees"),
  attendance: (t) => t("reports_page.sources.attendance", "Attendance"),
  timesheet: (t) => t("reports_page.sources.timesheet", "Monthly timesheet"),
  absences: (t) => t("reports_page.sources.absences", "Absences"),
  leave: (t) => t("reports_page.sources.leave", "Leave balances"),
  payroll: (t) => t("reports_page.sources.payroll", "Payroll"),
};

/** The source's display name; an unknown source keeps a readable form of its key. */
export function reportSourceLabel(t: T, source: string): string {
  return SOURCE_LABELS[source]?.(t) ?? reportFieldLabel(t, source);
}

/**
 * A group's name. Rows with no department, branch or grade group under an
 * empty key, which printed as a bare ": 1".
 */
export function reportGroupLabel(t: T, key: string): string {
  return key === "" ? t("reports_page.not_set", "Not set") : key;
}

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
