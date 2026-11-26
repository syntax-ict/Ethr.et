import { afterEach, describe, it, expect, vi } from "vitest";
import { act, render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { QuickTab } from "@/features/reports/components/quick-tab";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { setLocale } from "@/lib/i18n/translations";
import am from "@/lib/i18n/locales/am.json";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

afterEach(() => {
  act(() => setLocale("en"));
  localStorage.removeItem("locale");
  localStorage.removeItem("ethr.calendar");
});

const RELATED = [
  "late_and_early",
  "overtime_by_rate",
  "missing_punches",
  "attendance_by_department",
] as const;

function serve() {
  server.use(
    http.get("*/api/v1/reports/presets", () =>
      HttpResponse.json({
        organisation: { type: null, workforce_profile: null },
        presets: [
          {
            key: "monthly_timesheet",
            source: "timesheet",
            config: { source: "timesheet" },
            standard: true,
            recommended: false,
          },
          ...RELATED.map((key) => ({
            key,
            source: "timesheet",
            config: { source: "timesheet" },
            standard: true,
            recommended: false,
          })),
        ],
      }),
    ),
  );
}

function renderTab() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
  return render(<QuickTab />, { wrapper });
}

describe("Reports, the timesheet's related reports", () => {
  it("names each one and says what it shows", async () => {
    serve();
    renderTab();

    expect(await screen.findByText("Late and early leave")).toBeInTheDocument();
    expect(screen.getByText("Overtime by rate")).toBeInTheDocument();
    expect(screen.getByText("Missing punches")).toBeInTheDocument();
    expect(screen.getByText("Attendance by department")).toBeInTheDocument();
    expect(
      screen.getByText(/lateness payroll will deduct/),
    ).toBeInTheDocument();
  });

  it("offers the day-by-day grid only on the monthly timesheet", async () => {
    serve();
    renderTab();
    await screen.findByText("Late and early leave");

    const links = screen.getAllByRole("link", {
      name: /Open as a day-by-day grid/,
    });
    expect(links).toHaveLength(1);
    expect(links[0].getAttribute("href")).toMatch(
      /^\/reports\/timesheet\?from=\d{4}-\d{2}-\d{2}&to=\d{4}-\d{2}-\d{2}$/,
    );
  });

  it("reads in Amharic", async () => {
    act(() => setLocale("am"));
    serve();
    renderTab();

    for (const key of RELATED) {
      expect(
        await screen.findByText(am[`reports_page.presets.${key}.title`]),
      ).toBeInTheDocument();
    }
  });
});
