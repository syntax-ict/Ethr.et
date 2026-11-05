import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import VoiceInsightsPage from "@/app/(dashboard)/voice/insights/page";

/**
 * Voice insights draws the pulse topics as sideways bars with a table view
 * (charts upgrade, 2026-11-04), where a list of hand-drawn progress bars
 * stood. The empty sentence stays when no topic has enough answers.
 */
vi.mock("recharts", () => {
  const Pass = ({ children }: { children?: ReactNode }) => (
    <div>{children}</div>
  );
  const Nothing = () => null;
  return {
    ResponsiveContainer: Pass,
    AreaChart: Pass,
    Area: Nothing,
    BarChart: ({
      children,
      layout,
    }: {
      children?: ReactNode;
      layout?: string;
    }) => (
      <div data-testid="bar-chart" data-layout={layout}>
        {children}
      </div>
    ),
    Bar: Nothing,
    XAxis: ({ allowDecimals }: { allowDecimals?: boolean }) => (
      <div data-testid="x-axis" data-allow-decimals={String(allowDecimals)} />
    ),
    YAxis: ({ allowDecimals }: { allowDecimals?: boolean }) => (
      <div data-testid="y-axis" data-allow-decimals={String(allowDecimals)} />
    ),
    CartesianGrid: Nothing,
    Tooltip: Nothing,
  };
});

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    can: { manageVoice: true },
    isSupervisor: false,
    isAtLeast: () => false,
    hasRole: () => false,
    role: "hr_admin",
    isSuperAdmin: false,
  }),
}));

vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams(""),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  usePathname: () => "/voice/insights",
}));

function insights(tags: Record<string, number>) {
  return {
    min_group: 5,
    trend: [],
    recent: {
      average: 3.8,
      responses: 12,
      tags,
      departments: [],
      comments: [],
    },
  };
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>
        <VoiceInsightsPage />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

describe("voice topics chart", () => {
  it("draws the topics as sideways bars with a table", async () => {
    const user = userEvent.setup();
    server.use(
      http.get("*/api/v1/voice/pulse/insights", () =>
        HttpResponse.json(insights({ workload: 7, manager: 3 })),
      ),
      http.get("*/api/v1/voice/concerns", () =>
        HttpResponse.json({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
          counts: { new: 0, in_review: 0, resolved: 0, closed: 0 },
        }),
      ),
    );
    renderPage();

    const heading = await screen.findByRole("heading", {
      name: "What it is about",
    });
    const card = heading.closest("section") as HTMLElement;
    // The card shows its empty sentence until the insights land.
    expect(await within(card).findByTestId("bar-chart")).toHaveAttribute(
      "data-layout",
      "vertical",
    );
    // Sideways, the count axis is the X axis: whole mentions only.
    expect(within(card).getByTestId("x-axis")).toHaveAttribute(
      "data-allow-decimals",
      "false",
    );

    await user.click(
      within(card).getByRole("button", { name: "Show as table" }),
    );
    const table = within(card).getByRole("table", { name: "What it is about" });
    expect(
      within(table)
        .getAllByRole("columnheader")
        .map((th) => th.textContent),
    ).toEqual(["Topic", "Mentions"]);
    expect(within(table).getAllByRole("row")[1]).toHaveAccessibleName(/7$/);
  });

  it("keeps the empty sentence when no topic has enough answers", async () => {
    server.use(
      http.get("*/api/v1/voice/pulse/insights", () =>
        HttpResponse.json(insights({})),
      ),
      http.get("*/api/v1/voice/concerns", () =>
        HttpResponse.json({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
          counts: { new: 0, in_review: 0, resolved: 0, closed: 0 },
        }),
      ),
    );
    renderPage();

    expect(
      await screen.findByText(
        "Topics appear once enough people have answered.",
      ),
    ).toBeInTheDocument();
    expect(screen.queryByTestId("bar-chart")).toBeNull();
  });
});
