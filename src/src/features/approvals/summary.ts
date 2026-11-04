import { localizedName } from "@/lib/i18n/localizedName";

/**
 * What a pending request is about, with its dates in the reader's calendar.
 *
 * The server's `summary` names the dates in the Gregorian calendar, in the
 * request's language ("Nov 09 - Nov 12"), and sat under a submitted-on date
 * the page printed as Tikimt 24, 2019 (dashboard review, 2026-11-03, PR 12).
 * The dates now travel beside it as calendar dates, and this builds the line
 * through the same formatter as every other date on the page. An answer
 * without them, from before they were sent, falls back to the summary.
 */
export interface ApprovalSummarySource {
  type: string;
  summary: string;
  leave_type?: { name: string; name_am: string | null } | null;
  from?: string | null;
  to?: string | null;
  date?: string | null;
  hours?: number | null;
}

export function approvalSummary(
  item: ApprovalSummarySource,
  t: (
    key: string,
    fallback?: string,
    replacements?: Record<string, string | number>,
  ) => string,
  formatDate: (date: string) => string,
  locale: string,
): string {
  if (item.type === "leave" && item.from && item.to) {
    const type = item.leave_type
      ? localizedName(item.leave_type, locale)
      : t("dashboard.leave", "Leave");
    return t("approvals.leave_summary", ":type: :from - :to", {
      type,
      from: formatDate(item.from),
      to: formatDate(item.to),
    });
  }
  if (item.type === "correction" && item.date) {
    return t(
      "approvals.correction_summary",
      "Attendance correction for :date",
      { date: formatDate(item.date) },
    );
  }
  if (item.type === "overtime" && item.date) {
    return t("approvals.overtime_summary", "Overtime on :date, :hours hours", {
      date: formatDate(item.date),
      hours: item.hours ?? 0,
    });
  }
  return item.summary;
}
