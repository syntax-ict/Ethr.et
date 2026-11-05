import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { OrganisationSnapshot } from "@/features/dashboard/components/organisation-snapshot";

/**
 * The Organisation today band (owner review, 2026-11-05). Before it, a
 * morning read "0%" as if nobody had come, a 0.5% fall in payroll drew in
 * red, and approvals waiting looked the same as none.
 */

/** Midday in Addis Ababa on Tuesday 3 November 2026. */
const NOW = new Date("2026-11-03T09:00:00Z");

interface Overrides {
  today_present?: number;
  today_expected?: number;
  gross?: number;
  previousGross?: number;
  pending?: {
    leave: number;
    correction: number;
    profile_update: number;
    total: number;
  };
}

function serve({
  today_present = 37,
  today_expected = 40,
  gross = 995_000_00,
  previousGross = 1_000_000_00,
  pending = { leave: 1, correction: 2, profile_update: 0, total: 3 },
}: Overrides = {}) {
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
    http.get("*/api/v1/dashboard/executive", () =>
      HttpResponse.json({
        headcount: { active: 40, total: 46, by_department: [] },
        attendance_rate: {
          today: today_expected > 0 ? 0 : null,
          period: 84.7,
          today_present,
          today_expected,
        },
        payroll_summary: {
          gross_cents: gross,
          net_cents: 0,
          tax_cents: 0,
          previous_gross_cents: previousGross,
          employee_count: 40,
        },
        turnover: { rate: 0, exits: 0 },
        workforce_growth: [
          { month: "2019-01-EC", hires: 0, headcount: 38 },
          { month: "2019-02-EC", hires: 2, headcount: 40 },
        ],
        leave_utilization: null,
      }),
    ),
    http.get("*/api/v1/dashboard/manager", () =>
      HttpResponse.json({ pending_approvals: pending }),
    ),
  );
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(NOW);
  localStorage.setItem("ethr.calendar", "gregorian");
});

afterEach(() => {
  vi.useRealTimers();
  localStorage.removeItem("ethr.calendar");
});

function renderBand() {
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

function cell(name: RegExp) {
  return screen.getByRole("link", { name });
}

describe("<OrganisationSnapshot> band", () => {
  it("counts today's attendance in people, with a ring for the share", async () => {
    serve();
    renderBand();

    expect(await screen.findByText("of 40 in")).toBeInTheDocument();
    const present = cell(/Present Today/);
    expect(within(present).getByText("37")).toBeInTheDocument();
    const ring = within(present).getByRole("progressbar", {
      name: "Checked in today",
    });
    expect(ring).toHaveAttribute("aria-valuenow", "92.5");
    expect(ring).toHaveTextContent("93%");
  });

  it("says nobody has checked in yet rather than 0%", async () => {
    serve({ today_present: 0 });
    renderBand();

    expect(
      await screen.findByText("No one has checked in yet"),
    ).toBeInTheDocument();
    // The ring is an empty track: no "0%" in it, and no red.
    expect(screen.queryByText("0%")).not.toBeInTheDocument();
    expect(
      screen.getByRole("progressbar", { name: "Checked in today" }),
    ).toHaveAttribute("aria-valuetext", "0%");
  });

  it("names a day with no one expected instead of drawing an empty ring", async () => {
    serve({ today_present: 0, today_expected: 0 });
    renderBand();

    expect(await screen.findByText("No one scheduled")).toBeInTheDocument();
    expect(screen.queryByRole("progressbar")).not.toBeInTheDocument();
  });

  it("asks for a review only when something is waiting, naming each kind", async () => {
    serve();
    renderBand();

    const approvals = await screen.findByRole("link", {
      name: /Pending Approvals/,
    });
    expect(within(approvals).getByText("Review")).toBeInTheDocument();
    expect(
      within(approvals).getByText("1 leave · 2 corrections"),
    ).toBeInTheDocument();
  });

  it("is calm when nothing is waiting", async () => {
    serve({
      pending: { leave: 0, correction: 0, profile_update: 0, total: 0 },
    });
    renderBand();

    expect(
      await screen.findByText("Nothing is waiting for you"),
    ).toBeInTheDocument();
    expect(screen.queryByText("Review")).not.toBeInTheDocument();
  });

  it("draws a fall in payroll in grey, not red", async () => {
    serve();
    renderBand();

    const badge = (await screen.findByText("vs last month"))
      .previousElementSibling as HTMLElement;
    expect(badge).toHaveTextContent("0.5%");
    expect(badge.className).toContain("bg-neutral-soft");
    expect(badge.className).not.toContain("bg-destructive-soft");
  });

  it("links to Analytics for the detail", async () => {
    serve();
    renderBand();

    expect(
      await screen.findByRole("link", { name: /Open analytics/ }),
    ).toHaveAttribute("href", "/analytics");
  });
});
