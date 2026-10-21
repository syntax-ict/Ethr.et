import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { OverviewTab } from "@/app/(dashboard)/analytics/overview-tab";

function renderTab() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
  return render(<OverviewTab />, { wrapper });
}

const OVERVIEW = {
  headcount: { active: 40, total: 46, by_department: [] },
  attendance_rate: { today: 92 },
  payroll_summary: { net_cents: 259_000_000 },
  turnover: { rate: 4, exits: 3 },
  workforce_growth: null,
  leave_utilization: null,
};

// The Analytics tiles are the dashboard kit's StatCard now, not a local copy
// (FRONTEND_REDESIGN_PLAN.md, Phase 3).
describe("<OverviewTab>", () => {
  it("draws its figures in the shared tiles, counts inside the sentence", async () => {
    server.use(
      http.get("*/api/v1/dashboard/executive", () =>
        HttpResponse.json(OVERVIEW),
      ),
    );
    renderTab();

    expect(await screen.findByText("40")).toBeInTheDocument();
    // Was the number glued before a separately translated word.
    expect(screen.getByText("46 total")).toBeInTheDocument();
    expect(screen.getByText("3 exits")).toBeInTheDocument();
    // Fitted to the tile, exact on hover.
    const net = screen.getByText(/2\.59M/);
    expect(net).toHaveAttribute(
      "title",
      expect.stringMatching(/2,590,000\.00/),
    );
  });
});
