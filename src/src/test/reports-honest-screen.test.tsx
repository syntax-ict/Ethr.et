import { afterEach, beforeEach, describe, it, expect, vi } from "vitest";
import { act, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { BuilderTab } from "@/features/reports/components/builder-tab";
import { PreviewResult } from "@/features/reports/components/preview-result";
import { SavedTab } from "@/features/reports/components/saved-tab";
import { ScheduledTab } from "@/features/reports/components/scheduled-tab";
import type { ReportResult } from "@/features/reports/api";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { setLocale } from "@/lib/i18n/translations";
import am from "@/lib/i18n/locales/am.json";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: () => true,
    can: { viewReports: true },
    role: "hr_admin",
  }),
}));

// The plan's features are the one thing these tests vary.
let planHas = (_feature: string) => true;
vi.mock("@/features/auth/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/features/auth/api")>()),
  usePlanFeatures: () => ({ has: (f: string) => planHas(f), isLoading: false }),
}));

beforeEach(() => {
  planHas = () => true;
});

afterEach(() => {
  act(() => setLocale("en"));
  localStorage.removeItem("locale");
});

function Wrap({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return (
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
}

const SOURCES = {
  sources: {
    employees: {
      label: "Employees",
      fields: ["name", "status", "gender", "department"],
      filters: ["status", "department"],
    },
  },
};

function result(over: Partial<ReportResult> = {}): ReportResult {
  return {
    source: "attendance",
    total: 1,
    truncated: false,
    data: [],
    summary: [],
    ...over,
  } as ReportResult;
}

describe("Reports builder, an out-of-date preview", () => {
  it("cannot be saved or downloaded until it is run again", async () => {
    server.use(
      http.get("*/api/v1/reports/sources", () => HttpResponse.json(SOURCES)),
      http.post("*/api/v1/reports/generate", () =>
        HttpResponse.json(result({ source: "employees" })),
      ),
    );
    const user = userEvent.setup();
    render(<BuilderTab />, { wrapper: Wrap });

    await user.click(
      await screen.findByRole("button", { name: /Run Preview/ }),
    );
    const save = await screen.findByRole("button", {
      name: /Save as Template/,
    });
    expect(save).toBeEnabled();
    expect(screen.queryByRole("status")).not.toBeInTheDocument();

    // A filter with a value changes what would be saved.
    await user.click(screen.getByRole("button", { name: "Add filter" }));
    await user.click(screen.getByRole("combobox", { name: /value: status/i }));
    await user.click(await screen.findByRole("option", { name: "Confirmed" }));

    expect(screen.getByRole("status")).toHaveTextContent(
      "The settings have changed since this preview",
    );
    expect(
      screen.getByRole("button", { name: /Save as Template/ }),
    ).toBeDisabled();
    expect(screen.getByRole("button", { name: /Download CSV/ })).toBeDisabled();

    await user.click(screen.getByRole("button", { name: /Run Preview/ }));
    await waitFor(() =>
      expect(
        screen.getByRole("button", { name: /Save as Template/ }),
      ).toBeEnabled(),
    );
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Download CSV/ })).toBeEnabled();
  });
});

describe("Reports, saved tab", () => {
  const SAVED = {
    reports: [
      {
        public_id: "rep_1",
        name: "Weekly late list",
        config: { source: "attendance" },
        created_at: "2026-10-01T08:00:00Z",
        mine: true,
      },
    ],
  };

  it("offers Schedule only on a plan with custom reports", async () => {
    server.use(
      http.get("*/api/v1/reports/saved", () => HttpResponse.json(SAVED)),
    );
    planHas = (f) => f !== "custom_reports";
    const { unmount } = render(<SavedTab />, { wrapper: Wrap });
    await screen.findByText("Weekly late list");
    expect(screen.getByRole("button", { name: /Run/ })).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /Schedule/ }),
    ).not.toBeInTheDocument();
    unmount();

    planHas = () => true;
    render(<SavedTab />, { wrapper: Wrap });
    await screen.findByText("Weekly late list");
    expect(
      screen.getByRole("button", { name: /Schedule/ }),
    ).toBeInTheDocument();
  });
});

describe("Reports, scheduled tab", () => {
  it("names the frequency in words and counts recipients in the singular", async () => {
    server.use(
      http.get("*/api/v1/reports/scheduled", () =>
        HttpResponse.json({
          schedules: [
            {
              public_id: "s1",
              report_name: "Payroll by month",
              frequency: "weekly",
              recipients: ["a@example.com", "b@example.com"],
              next_run_at: null,
              last_run_at: null,
            },
            {
              public_id: "s2",
              report_name: "Headcount",
              frequency: "monthly",
              recipients: ["c@example.com"],
              next_run_at: null,
              last_run_at: null,
            },
          ],
        }),
      ),
    );
    render(<ScheduledTab />, { wrapper: Wrap });

    expect(
      await screen.findByText("Weekly, Monday 6:00 AM"),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Monthly, on the 1st at 6:00 AM"),
    ).toBeInTheDocument();
    expect(screen.queryByText("weekly")).not.toBeInTheDocument();
    expect(screen.getByText(/2 recipients/)).toBeInTheDocument();
    expect(screen.getByText(/1 recipient$/)).toBeInTheDocument();
  });
});

describe("Reports, result on paper", () => {
  const heading = () => screen.getByTestId("report-print-heading");

  it("says what the report is and the period it covers", () => {
    render(
      <PreviewResult
        result={result({ source: "timesheet" })}
        config={{
          source: "timesheet",
          filters: { from: "2026-10-11", to: "2026-11-09" },
        }}
      />,
      { wrapper: Wrap },
    );
    expect(within(heading()).getByRole("heading")).toHaveTextContent(
      "Monthly timesheet",
    );
    expect(heading()).toHaveTextContent(
      "Period: Tikimt 1, 2019 to Tikimt 30, 2019",
    );
    // On screen it is hidden; on paper it shows.
    expect(heading().className).toContain("print:block");
    expect(heading().className).toContain("hidden");
  });

  it("uses the report's own name when it has one, and no period when it has none", () => {
    render(
      <PreviewResult
        result={result({ source: "employees" })}
        title="Headcount by department"
        config={{ source: "employees" }}
      />,
      { wrapper: Wrap },
    );
    expect(heading()).toHaveTextContent("Headcount by department");
    expect(heading()).not.toHaveTextContent("Period");
  });

  it("reads in Amharic, period included", () => {
    act(() => setLocale("am"));
    render(
      <PreviewResult
        result={result({ source: "timesheet" })}
        config={{
          source: "timesheet",
          filters: { from: "2026-10-11", to: "2026-11-09" },
        }}
      />,
      { wrapper: Wrap },
    );
    expect(heading()).toHaveTextContent(am["reports_page.sources.timesheet"]);
    expect(heading()).toHaveTextContent("ጊዜ:");
    expect(heading()).toHaveTextContent("እስከ");
  });

  it("carries every loaded row, showing the first 200 on screen", () => {
    const data = Array.from({ length: 205 }, (_, i) => ({ name: `E${i}` }));
    render(<PreviewResult result={result({ data, total: 205 })} />, {
      wrapper: Wrap,
    });
    const rows = screen.getAllByRole("row");
    // The header row plus every loaded row.
    expect(rows).toHaveLength(206);
    expect(rows[200].className).not.toContain("print:table-row");
    expect(rows[201].className).toContain("print:table-row");
    expect(
      screen.getByText(
        "Showing the first 200 of 205 rows. Download CSV for full data.",
      ),
    ).toBeInTheDocument();
  });
});

describe("Reports, stored values in the reader's language", () => {
  const data = [
    { name: "Abebe", status: "holiday", gender: "male", source: "web" },
  ];

  it("shows a status, gender and source as words, and leaves names alone", () => {
    render(
      <PreviewResult
        result={result({
          data,
          summary: { grouped_by: "status", groups: { late: 2, "": 1 } },
        })}
      />,
      { wrapper: Wrap },
    );
    expect(screen.getByRole("cell", { name: "Holiday" })).toBeInTheDocument();
    expect(screen.getByRole("cell", { name: "Male" })).toBeInTheDocument();
    expect(screen.getByRole("cell", { name: "Web" })).toBeInTheDocument();
    expect(screen.getByRole("cell", { name: "Abebe" })).toBeInTheDocument();
    expect(screen.getByText(/^Late:/)).toBeInTheDocument();
    expect(screen.getByText(/^Not set:/)).toBeInTheDocument();
  });

  it("reads the same values in Amharic", () => {
    act(() => setLocale("am"));
    render(<PreviewResult result={result({ data })} />, { wrapper: Wrap });
    expect(
      screen.getByRole("cell", { name: am["status.holiday"] }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("cell", { name: am["common.male"] }),
    ).toBeInTheDocument();
    expect(screen.getByRole("cell", { name: "Abebe" })).toBeInTheDocument();
  });
});
