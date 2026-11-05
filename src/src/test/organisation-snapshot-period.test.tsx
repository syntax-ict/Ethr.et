import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse, type JsonBodyType } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { OrganisationSnapshot } from "@/features/dashboard/components/organisation-snapshot";

/**
 * The Organisation today tiles sent no period and took the API's default,
 * which was the Gregorian month, while the Analytics page built "This month"
 * in the reader's calendar: the same organisation read 45% attendance on the
 * tile and 84.7% on Analytics for Tikimt 2019 (dashboard review, 2026-11-03).
 * The tiles now send the month the reader's calendar names.
 */

/** Midday in Addis Ababa on 3 November 2026, Tikimt 23, 2019. */
const NOW = new Date("2026-11-03T09:00:00Z");

const asked: Record<string, { from: string | null; to: string | null }> = {};

function recording(path: string, body: JsonBodyType) {
  return http.get(`*/api/v1${path}`, ({ request }) => {
    const url = new URL(request.url);
    asked[path] = {
      from: url.searchParams.get("from"),
      to: url.searchParams.get("to"),
    };
    return HttpResponse.json(body);
  });
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(NOW);
  for (const key of Object.keys(asked)) delete asked[key];
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "01HZADMIN", name: "Hana", role: "tenant_admin" },
        permissions: [],
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
      }),
    ),
    recording("/dashboard/executive", {
      headcount: { active: 40, total: 46, by_department: [] },
      attendance_rate: {
        today: 92,
        period: 84.7,
        today_present: 37,
        today_expected: 40,
        recent: [],
      },
      payroll_summary: { gross_cents: 0, previous_gross_cents: 0 },
      turnover: { rate: 0, exits: 0 },
      workforce_growth: [{ month: "2019-02-EC", hires: 2, headcount: 40 }],
      leave_utilization: null,
    }),
    http.get("*/api/v1/dashboard/manager", () =>
      HttpResponse.json({
        pending_approvals: {
          leave: 1,
          correction: 2,
          profile_update: 0,
          total: 3,
        },
      }),
    ),
  );
});

afterEach(() => {
  vi.useRealTimers();
  localStorage.removeItem("ethr.calendar");
});

function renderSnapshot() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>
        <OrganisationSnapshot />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

describe("<OrganisationSnapshot> period", () => {
  it("asks for the reader's Ethiopian month, as the Analytics page does", async () => {
    renderSnapshot();

    expect(await screen.findByText("84.7% this month")).toBeInTheDocument();

    // Tikimt 2019 runs 11 October to 9 November 2026.
    const tikimt = { from: "2026-10-11", to: "2026-11-09" };
    expect(asked["/dashboard/executive"]).toEqual(tikimt);
    expect(screen.getByText("2 hired this month")).toBeInTheDocument();
  });

  it("asks for the Gregorian month when the reader chose that calendar", async () => {
    localStorage.setItem("ethr.calendar", "gregorian");
    renderSnapshot();

    await waitFor(() => expect(asked["/dashboard/executive"]).toBeDefined());

    const november = { from: "2026-11-01", to: "2026-11-30" };
    expect(asked["/dashboard/executive"]).toEqual(november);
  });
});
