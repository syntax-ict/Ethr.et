import type { ReportConfig } from "@/features/reports/api";

/** What the attendance page holds: the same values as its filter bar and address. */
export interface RegisterFilters {
  /** "all" or an attendance status. */
  status: string;
  /** "all" or a punch source (web, kiosk, ...). */
  source: string;
  /** YYYY-MM-DD, Gregorian, or "". */
  from: string;
  to: string;
}

/**
 * The body of `POST /reports/export` for "the whole register on paper".
 * `ReportEngine` filters attendance by period, dates, status, department and
 * branch. The page's status and dates map one to one (statuses are the stored
 * values, dates are ISO). The punch source has no counterpart there, so it is
 * reported in `unapplied` and never dropped silently.
 */
export function registerPdfRequest(filters: RegisterFilters): {
  body: ReportConfig & { source: "attendance"; format: "pdf" };
  unapplied: Array<"source">;
} {
  const applied: NonNullable<ReportConfig["filters"]> = {};
  if (filters.from) applied.from = filters.from;
  if (filters.to) applied.to = filters.to;
  if (filters.status && filters.status !== "all") {
    applied.status = filters.status;
  }

  return {
    body: {
      source: "attendance",
      format: "pdf",
      ...(Object.keys(applied).length > 0 ? { filters: applied } : {}),
    },
    unapplied: filters.source && filters.source !== "all" ? ["source"] : [],
  };
}
