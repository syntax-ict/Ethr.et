import type { useT } from "@/lib/i18n/useT";

type T = ReturnType<typeof useT>["t"];

/**
 * The codes a timesheet cell can carry, in the order the legend lists them.
 * `App\Enums\TimesheetCode` is the one definition; these are its values, each
 * with the letters the server prints (`short`). A day still to come has no
 * code at all, and shows as a dot.
 */
export const TIMESHEET_CODES = [
  { code: "present", short: "P" },
  { code: "late", short: "L" },
  { code: "early_leave", short: "E" },
  { code: "missing_punch", short: "M" },
  { code: "absent", short: "A" },
  { code: "annual_leave", short: "AL" },
  { code: "sick_leave", short: "SL" },
  { code: "maternity_leave", short: "ML" },
  { code: "other_leave", short: "OL" },
  { code: "field_duty", short: "FD" },
  { code: "half_day_leave", short: "½" },
  { code: "holiday", short: "H" },
  { code: "rest_day", short: "R" },
] as const;

/** Semantic tokens only: a code is colour and letters, so it reads without colour. */
const TONE: Record<string, string> = {
  present: "bg-status-success/10 text-status-success",
  late: "bg-status-warning/15 text-status-warning",
  early_leave: "bg-status-warning/15 text-status-warning",
  missing_punch: "bg-status-warning/15 text-status-warning",
  absent: "bg-status-error/10 text-status-error",
  annual_leave: "bg-status-info/10 text-status-info",
  sick_leave: "bg-status-info/10 text-status-info",
  maternity_leave: "bg-status-info/10 text-status-info",
  other_leave: "bg-status-info/10 text-status-info",
  field_duty: "bg-status-info/10 text-status-info",
  half_day_leave: "bg-status-info/10 text-status-info",
  holiday: "bg-muted text-muted-foreground",
  rest_day: "bg-muted/60 text-muted-foreground",
  not_employed: "text-muted-foreground",
};

export function codeTone(code: string | null): string {
  return (code && TONE[code]) || "text-muted-foreground";
}

/** The code's name in the reader's language. Literal `t()` calls so the i18n gate sees every key. */
export function codeLabel(t: T, code: string): string {
  switch (code) {
    case "present":
      return t("reports_page.timesheet.code.present", "Present");
    case "late":
      return t("reports_page.timesheet.code.late", "Present, late");
    case "early_leave":
      return t("reports_page.timesheet.code.early_leave", "Left early");
    case "missing_punch":
      return t("reports_page.timesheet.code.missing_punch", "Missing punch");
    case "absent":
      return t("reports_page.timesheet.code.absent", "Absent");
    case "annual_leave":
      return t("reports_page.timesheet.code.annual_leave", "Annual leave");
    case "sick_leave":
      return t("reports_page.timesheet.code.sick_leave", "Sick leave");
    case "maternity_leave":
      return t(
        "reports_page.timesheet.code.maternity_leave",
        "Maternity leave",
      );
    case "other_leave":
      return t("reports_page.timesheet.code.other_leave", "Other leave");
    case "field_duty":
      return t("reports_page.timesheet.code.field_duty", "Field duty");
    case "half_day_leave":
      return t("reports_page.timesheet.code.half_day_leave", "Half day leave");
    case "holiday":
      return t("reports_page.timesheet.code.holiday", "Public holiday");
    case "rest_day":
      return t("reports_page.timesheet.code.rest_day", "Weekly rest day");
    case "not_employed":
      return t("reports_page.timesheet.code.not_employed", "Not employed");
    default:
      return code;
  }
}

/** The period's status, in words. */
export function statusLabel(t: T, code: string): string {
  switch (code) {
    case "paid":
      return t("reports_page.timesheet.status.paid", "Paid, locked");
    case "part_paid":
      return t("reports_page.timesheet.status.part_paid", "Part paid");
    case "exceptions":
      return t("reports_page.timesheet.status.exceptions", "Exceptions to fix");
    case "reconciled":
      return t("reports_page.timesheet.status.reconciled", "Reconciled");
    default:
      return t("reports_page.timesheet.status.open", "Open");
  }
}

export function statusTone(code: string): string {
  switch (code) {
    case "paid":
      return "bg-status-success/10 text-status-success";
    case "part_paid":
      return "bg-status-info/10 text-status-info";
    case "exceptions":
      return "bg-status-warning/15 text-status-warning";
    case "reconciled":
      return "bg-status-success/10 text-status-success";
    default:
      return "bg-muted text-muted-foreground";
  }
}

/** Minutes as `h:mm`, the way a timesheet reads hours. */
export function clock(minutes: number): string {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return `${h}:${String(m).padStart(2, "0")}`;
}
