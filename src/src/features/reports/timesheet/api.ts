import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { useCallback, useSyncExternalStore } from "react";
import { apiClient } from "@/api/client";
import type { operations } from "@/api/generated";
import type { CalendarSystem } from "@/lib/calendar/calendar-context";
import { toEthiopian } from "@/lib/calendar/ethiopian";
import {
  currentReportMonth,
  reportMonthRange,
  type ReportMonth,
} from "@/features/reports/components/report-month-picker";

/** `GET /reports/timesheet`: a page of people, one cell a day, and everyone's totals. */
export type Timesheet =
  operations["report.timesheet"]["responses"][200]["content"]["application/json"];
export type TimesheetRow = Timesheet["data"][number];
export type TimesheetCell = TimesheetRow["cells"][number];
export type TimesheetStatus = NonNullable<Timesheet["status"]>;

export interface TimesheetFilters {
  /** First and last day of the month, Gregorian, as the API reads them. */
  from: string | null;
  to: string | null;
  department: string;
  branch: string;
  shift: string;
  employment_status: string;
  page: number;
}

const KEYS = [
  "from",
  "to",
  "department",
  "branch",
  "shift",
  "employment_status",
] as const;

const CHANGE_EVENT = "ethr:timesheet-filters";

function subscribe(onChange: () => void): () => void {
  window.addEventListener("popstate", onChange);
  window.addEventListener(CHANGE_EVENT, onChange);
  return () => {
    window.removeEventListener("popstate", onChange);
    window.removeEventListener(CHANGE_EVENT, onChange);
  };
}

/**
 * The timesheet's filters, kept in the address so a reload, a shared link and
 * Back all land on the same sheet. Read with `useSyncExternalStore`, as
 * `useQueryTab` does, because the static export prerenders this page and the
 * server snapshot (no filters) must match the first client render.
 *
 * A filter that is empty is left out of the address; the month is `from` and
 * `to`, which a month in either calendar has.
 */
export function useTimesheetFilters(): [
  TimesheetFilters,
  (change: Partial<TimesheetFilters>) => void,
] {
  const search = useSyncExternalStore(
    subscribe,
    () => window.location.search,
    () => "",
  );

  const params = new URLSearchParams(search);
  const page = Number(params.get("page"));
  const filters: TimesheetFilters = {
    from: params.get("from"),
    to: params.get("to"),
    department: params.get("department") ?? "",
    branch: params.get("branch") ?? "",
    shift: params.get("shift") ?? "",
    employment_status: params.get("employment_status") ?? "",
    page: Number.isInteger(page) && page > 0 ? page : 1,
  };

  const set = useCallback((change: Partial<TimesheetFilters>) => {
    const next = new URLSearchParams(window.location.search);
    for (const key of KEYS) {
      const value = change[key];
      if (value === undefined) continue;
      if (value) next.set(key, value);
      else next.delete(key);
    }
    // Any filter but the page itself sends the list back to its first page.
    const page = change.page ?? 1;
    if (page > 1) next.set("page", String(page));
    else next.delete("page");

    const query = next.toString();
    window.history.replaceState(
      null,
      "",
      `${window.location.pathname}${query ? `?${query}` : ""}${window.location.hash}`,
    );
    window.dispatchEvent(new Event(CHANGE_EVENT));
  }, []);

  return [filters, set];
}

/** The month a `from` date opens, in the viewer's calendar. */
export function monthOf(from: string, calendar: CalendarSystem): ReportMonth {
  const [year, month, day] = from.split("-").map(Number);
  if (calendar === "ethiopian") {
    const e = toEthiopian(year, month, day);
    return { calendar, year: e.year, month: e.month };
  }
  return { calendar, year, month };
}

/** The days to ask for: the address's, else this month in the viewer's calendar. */
export function periodOf(
  filters: Pick<TimesheetFilters, "from" | "to">,
  calendar: CalendarSystem,
): { from: string; to: string } {
  return filters.from && filters.to
    ? { from: filters.from, to: filters.to }
    : reportMonthRange(currentReportMonth(calendar));
}

const PER_PAGE = 25;

export function useTimesheet(
  filters: TimesheetFilters,
  period: { from: string; to: string },
) {
  return useQuery<Timesheet>({
    queryKey: ["reports", "timesheet", period, filters],
    queryFn: async () => {
      const { data } = await apiClient.get("/reports/timesheet", {
        params: {
          ...period,
          department: filters.department || undefined,
          branch: filters.branch || undefined,
          shift: filters.shift || undefined,
          employment_status: filters.employment_status || undefined,
          page: filters.page,
          per_page: PER_PAGE,
        },
      });
      return data;
    },
    placeholderData: keepPreviousData,
    staleTime: 60 * 1000,
  });
}
