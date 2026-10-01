// Pinned before anything reads the clock: the defects below exist only east of
// UTC, and CI runs in UTC, where they cannot show. Node re-reads TZ on
// assignment; the first test asserts it took effect rather than trusting it.
const ORIGINAL_TZ = process.env.TZ;
process.env.TZ = "Africa/Addis_Ababa";

import { describe, it, expect, vi, afterEach, afterAll } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import type { components } from "@/api/generated";
import RosterPage from "@/app/(dashboard)/shifts/roster/page";
import ShiftRotationsPage from "@/app/(dashboard)/shifts/rotations/page";
import {
  addDaysIso,
  localIsoDate,
  mondayOf,
  toHHMM,
} from "@/features/shifts/dates";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

type Shift = components["schemas"]["ShiftResource"];
type Assignment = components["schemas"]["ShiftAssignmentResource"];

const MORNING: Shift = {
  public_id: "01HZSHIFT00000000000000001",
  name: "Morning",
  name_am: null,
  start_time: "08:30:00",
  end_time: "17:30:00",
  crosses_midnight: false,
  grace_minutes: 15,
  early_departure_minutes: 15,
  break_minutes: 60,
  working_days: "1,2,3,4,5",
  is_default: false,
  is_active: true,
  created_at: "2026-09-01T06:00:00Z",
  updated_at: "2026-09-01T06:00:00Z",
};

function assignment(overrides: Partial<Assignment> = {}): Assignment {
  return {
    shift: MORNING,
    is_rotation: false,
    anchor_date: null,
    assignable_type: "Employee",
    effective_from: "2026-09-01",
    effective_to: null,
    created_at: "2026-09-01T06:00:00Z",
    ...overrides,
  };
}

function paged<T>(rows: T[], request: Request) {
  const url = new URL(request.url);
  const page = Number(url.searchParams.get("page") ?? 1);
  const perPage = Math.min(Number(url.searchParams.get("per_page") ?? 25), 100);
  return HttpResponse.json({
    data: rows.slice((page - 1) * perPage, page * perPage),
    meta: {
      current_page: page,
      last_page: Math.max(1, Math.ceil(rows.length / perPage)),
      per_page: perPage,
      total: rows.length,
    },
    links: {},
  });
}

function serve(schedule: Assignment[], log: URLSearchParams[] = []) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Hana", role: "hr_admin" },
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
        permissions: ["shift.viewAny", "shift.create", "shift.update"],
      }),
    ),
    http.get("*/api/v1/shifts", ({ request }) => paged([MORNING], request)),
    http.get("*/api/v1/organization/departments", ({ request }) =>
      paged([], request),
    ),
    http.get("*/api/v1/organization/branches", ({ request }) =>
      paged([], request),
    ),
    http.get("*/api/v1/shifts/schedule", ({ request }) => {
      log.push(new URL(request.url).searchParams);
      return paged(schedule, request);
    }),
  );
  return log;
}

/** Wall-clock `iso` 10:00 in Addis (07:00 UTC). */
function setToday(iso: string) {
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(new Date(`${iso}T07:00:00Z`));
}

function renderPage(ui: ReactNode) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{ui}</CalendarProvider>
    </QueryClientProvider>,
  );
}

/** The month-view cell whose date number is `day`. */
function monthCell(day: number): HTMLElement {
  const label = screen
    .getAllByText(String(day), { selector: "p" })
    .find((p) => p.parentElement?.className.includes("min-h-[80px]"));
  return label!.parentElement!;
}

afterEach(() => {
  vi.useRealTimers();
});

afterAll(() => {
  process.env.TZ = ORIGINAL_TZ;
});

describe("the host clock these tests depend on", () => {
  it("is Addis Ababa, UTC+3", () => {
    expect(new Date(2026, 9, 5).getTimezoneOffset()).toBe(-180);
  });
});

describe("date helpers", () => {
  it("names local dates without passing through UTC", () => {
    expect(localIsoDate(new Date(2026, 9, 5))).toBe("2026-10-05");
    expect(addDaysIso("2026-10-01", 13)).toBe("2026-10-14");
    expect(addDaysIso("2026-10-31", 1)).toBe("2026-11-01");
  });

  it("starts a week on the Monday before a Sunday, not after it", () => {
    expect(localIsoDate(mondayOf(new Date(2026, 9, 11)))).toBe("2026-10-05");
    expect(localIsoDate(mondayOf(new Date(2026, 9, 5)))).toBe("2026-10-05");
  });

  it("trims a shift time to HH:MM", () => {
    expect(toHHMM("08:30:00")).toBe("08:30");
    expect(toHHMM("08:30")).toBe("08:30");
  });
});

describe("Shift roster in Addis Ababa", () => {
  it("draws a Monday–Friday shift on Monday through Friday", async () => {
    setToday("2026-10-07");
    serve([assignment()]);
    renderPage(<RosterPage />);

    await screen.findAllByText("Morning", { selector: "div" });

    // October 2026: the 5th is a Monday, the 9th a Friday, the 10th a Saturday.
    // The cells were keyed by `toISOString()` — the previous day in UTC+3 —
    // so this drew the shift Tuesday through Saturday.
    expect(within(monthCell(5)).queryByText("Morning")).toBeInTheDocument();
    expect(within(monthCell(9)).queryByText("Morning")).toBeInTheDocument();
    expect(within(monthCell(10)).queryByText("Morning")).toBeNull();
    expect(within(monthCell(4)).queryByText("Morning")).toBeNull();
  });

  it("asks for the whole month, every page of it", async () => {
    setToday("2026-10-07");
    const many = Array.from({ length: 130 }, () => assignment());
    const log = serve(many);
    renderPage(<RosterPage />);

    await waitFor(() => expect(log.length).toBeGreaterThanOrEqual(2));
    expect(log[0].get("filter[date_from]")).toBe("2026-10-01");
    // Was 2026-10-30: the month's last day, named through UTC.
    expect(log[0].get("filter[date_to]")).toBe("2026-10-31");
    expect(log.map((p) => p.get("page"))).toEqual(["1", "2"]);
  });

  it("shows the current week on a Sunday, not the next one", async () => {
    setToday("2026-10-11"); // a Sunday
    serve([assignment()]);
    const user = userEvent.setup();
    renderPage(<RosterPage />);

    await user.click(await screen.findByRole("combobox", { name: "View" }));
    await user.click(await screen.findByRole("option", { name: /Week/ }));

    expect(
      await screen.findByText("2026-10-05 – 2026-10-11"),
    ).toBeInTheDocument();
  });
});

describe("Rotation preview in Addis Ababa", () => {
  it("asks for exactly two cycles starting today", async () => {
    setToday("2026-10-07");
    let previewed: URLSearchParams | null = null;
    serve([]);
    server.use(
      http.get("*/api/v1/shift-rotations", ({ request }) =>
        paged(
          [
            {
              public_id: "01HZROTATION00000000000001",
              name: "Weekly",
              name_am: null,
              description: null,
              cycle_days: 7,
              is_active: true,
              steps: [],
              assignments_count: 0,
              created_at: "2026-09-01T06:00:00Z",
              updated_at: "2026-09-01T06:00:00Z",
            },
          ],
          request,
        ),
      ),
      http.get("*/api/v1/shift-rotations/:id/preview", ({ request }) => {
        previewed = new URL(request.url).searchParams;
        return HttpResponse.json({
          rotation: {},
          anchor_date: "2026-10-07",
          days: [],
        });
      }),
    );
    const user = userEvent.setup();
    renderPage(<ShiftRotationsPage />);

    await user.click(await screen.findByRole("button", { name: "Preview" }));

    await waitFor(() => expect(previewed).not.toBeNull());
    expect(previewed!.get("from")).toBe("2026-10-07");
    // 14 days inclusive. `addDaysIso` went through toISOString() and landed
    // a day short in UTC+3, so the preview showed 13 days, not two cycles.
    expect(previewed!.get("to")).toBe("2026-10-20");
  });
});
