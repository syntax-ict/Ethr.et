import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import AttendanceIntelligencePage from "@/app/(dashboard)/attendance/intelligence/page";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

// The page is wrapped in <RoleGate minRole="hr_admin">; grant access directly.
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    can: {},
    role: "hr_admin",
  }),
}));

const INTELLIGENCE_URL = "*/api/v1/attendance/intelligence";

const EMPTY_RESPONSE = {
  date: "2026-08-18",
  anomalies: {
    count: 0,
    thresholds: {
      excessive_hours_minutes: 960,
      excessive_overtime_minutes: 240,
    },
    records: [],
  },
  late_arrivals: { count: 0, records: [] },
  early_departures: { count: 0, records: [] },
  missing_punches: { count: 0, records: [] },
};

function renderPage() {
  // DualCalendarDateInput defaults to Ethiopian entry mode unless a calendar
  // preference is stored — force Gregorian so the page's single date field
  // renders the same way these assertions expect.
  localStorage.setItem("ethr.calendar", "gregorian");
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
  return render(<AttendanceIntelligencePage />, { wrapper: Wrapper });
}

describe("<AttendanceIntelligencePage>", () => {
  it("shows an empty state when nothing was flagged", async () => {
    server.use(
      http.get(INTELLIGENCE_URL, () => HttpResponse.json(EMPTY_RESPONSE)),
    );
    renderPage();

    expect(
      await screen.findByText("No anomalies detected"),
    ).toBeInTheDocument();
  });

  it("lists an anomaly with its type and explanation", async () => {
    server.use(
      http.get(INTELLIGENCE_URL, () =>
        HttpResponse.json({
          ...EMPTY_RESPONSE,
          anomalies: {
            count: 1,
            thresholds: {
              excessive_hours_minutes: 960,
              excessive_overtime_minutes: 240,
            },
            records: [
              {
                employee_public_id: "01HZANM001",
                employee_name: "Abebe Kebede",
                types: ["excessive_hours"],
                worked_minutes: 1140,
                overtime_minutes: 0,
              },
            ],
          },
        }),
      ),
    );
    renderPage();

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    expect(screen.getByText("Excessive hours")).toBeInTheDocument();
    expect(
      screen.getByText("Worked 19h 0m (threshold 16h 0m)"),
    ).toBeInTheDocument();
  });
});
