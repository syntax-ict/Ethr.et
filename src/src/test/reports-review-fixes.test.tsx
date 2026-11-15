import { afterEach, beforeEach, describe, it, expect, vi } from "vitest";
import { act, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { BuilderTab } from "@/features/reports/components/builder-tab";
import { PreviewResult } from "@/features/reports/components/preview-result";
import { QuickTab } from "@/features/reports/components/quick-tab";
import type { ReportResult } from "@/features/reports/api";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { setLocale } from "@/lib/i18n/translations";
import { saveCsv } from "@/lib/utils/csv-export";
import am from "@/lib/i18n/locales/am.json";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

vi.mock("@/lib/utils/csv-export", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/utils/csv-export")>()),
  saveCsv: vi.fn(),
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

beforeEach(() => vi.clearAllMocks());

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

function result(over: Partial<ReportResult> = {}): ReportResult {
  return {
    source: "attendance",
    total: 1,
    matched: 1,
    truncated: false,
    period: null,
    data: [],
    summary: [],
    ...over,
  } as unknown as ReportResult;
}

const SOURCES = {
  sources: {
    employees: {
      label: "Employees",
      fields: ["name", "status", "department"],
      filters: ["status"],
    },
    attendance: {
      label: "Attendance",
      fields: ["employee_name", "date", "status"],
      filters: ["from", "to", "period", "status"],
    },
    timesheet: {
      label: "Monthly timesheet",
      fields: ["employee_name", "work_days"],
      filters: ["from", "to", "period"],
    },
  },
};

function serveBuilder(
  onGenerate: (body: Record<string, unknown>) => void,
  response: Partial<ReportResult> = {},
) {
  server.use(
    http.get("*/api/v1/reports/sources", () => HttpResponse.json(SOURCES)),
    http.post("*/api/v1/reports/generate", async ({ request }) => {
      const body = (await request.json()) as Record<string, unknown>;
      onGenerate(body);
      return HttpResponse.json(
        result({ source: body.source as string, ...response }),
      );
    }),
  );
}

async function chooseSource(
  user: ReturnType<typeof userEvent.setup>,
  label: string,
) {
  await user.click(
    await screen.findByRole("combobox", { name: "Data Source" }),
  );
  await user.click(await screen.findByRole("option", { name: label }));
}

const unknownTotal = result({
  truncated: true,
  matched: 3,
  total: 3,
  data: [{ name: "A" }, { name: "B" }, { name: "C" }],
});

describe("Reports, a cut result whose total is unknown", () => {
  // The server reports `matched` equal to the rows returned when it could not
  // count the rest.
  it("states no total on screen", () => {
    render(<PreviewResult result={unknownTotal} />, { wrapper: Wrap });
    expect(
      screen.getByText(
        "Showing only the newest 3 rows. Narrow the dates or filters to see the rest.",
      ),
    ).toBeInTheDocument();
    expect(screen.queryByText(/ of 3 rows/)).not.toBeInTheDocument();
  });

  it("states none in the Amharic note either", () => {
    act(() => setLocale("am"));
    render(<PreviewResult result={unknownTotal} />, { wrapper: Wrap });
    expect(
      screen.getByText(
        am["reports_page.truncated_only"].replace(":shown", "3"),
      ),
    ).toBeInTheDocument();
  });

  it("states none in the downloaded file", async () => {
    serveBuilder(() => {}, unknownTotal);
    const user = userEvent.setup();
    render(<BuilderTab />, { wrapper: Wrap });

    await user.click(
      await screen.findByRole("button", { name: /Run Preview/ }),
    );
    await user.click(
      await screen.findByRole("button", { name: /Download CSV/ }),
    );

    const csv = vi.mocked(saveCsv).mock.calls[0][1];
    expect(csv.split("\n").slice(-2)).toEqual([
      "",
      "Showing only the newest 3 rows. Narrow the dates or filters to see the rest.",
    ]);
  });
});

describe("Reports, quick tab download of a cut result", () => {
  it("ends the file with the note, total included when it is known", async () => {
    server.use(
      http.get("*/api/v1/reports/presets", () =>
        HttpResponse.json({
          organisation: {
            type: "private",
            workforce_profile: "standard_office",
          },
          presets: [
            {
              key: "absences",
              source: "absences",
              config: { source: "absences" },
              standard: true,
              recommended: false,
            },
          ],
        }),
      ),
      http.post("*/api/v1/reports/generate", () =>
        HttpResponse.json(
          result({
            source: "absences",
            truncated: true,
            matched: 2500,
            total: 2,
            data: [{ name: "A" }, { name: "B" }],
          }),
        ),
      ),
    );
    const user = userEvent.setup();
    render(<QuickTab />, { wrapper: Wrap });

    await user.click(await screen.findByRole("button", { name: /Run/ }));
    await user.click(
      await screen.findByRole("button", { name: /Download CSV/ }),
    );

    const csv = vi.mocked(saveCsv).mock.calls[0][1];
    expect(csv.split("\n").slice(-2)).toEqual([
      "",
      "Showing the newest 2 of 2500 rows. Narrow the dates or filters to see the rest.",
    ]);
  });
});

describe("Reports builder, a timesheet's periods", () => {
  async function openPeriod(user: ReturnType<typeof userEvent.setup>) {
    await user.click(screen.getByRole("button", { name: "Add filter" }));
    await user.click(screen.getByRole("combobox", { name: "Filter field" }));
    await user.click(await screen.findByRole("option", { name: "Period" }));
    await user.click(screen.getByRole("combobox", { name: /value: period/i }));
    const listbox = await screen.findByRole("listbox");
    return within(listbox)
      .getAllByRole("option")
      .map((o) => o.textContent);
  }

  it("offers only the short ones, and attendance all eight", async () => {
    serveBuilder(() => {});
    const user = userEvent.setup();
    render(<BuilderTab />, { wrapper: Wrap });

    await chooseSource(user, "Monthly timesheet");
    expect(await openPeriod(user)).toEqual([
      "This month",
      "Last month",
      "Yesterday",
      "Last 7 days",
    ]);
  });

  it("offers every period to attendance", async () => {
    serveBuilder(() => {});
    const user = userEvent.setup();
    render(<BuilderTab />, { wrapper: Wrap });

    await chooseSource(user, "Attendance");
    expect(await openPeriod(user)).toHaveLength(8);
  });

  it("drops a long period when the source becomes a timesheet", async () => {
    let body: Record<string, unknown> | null = null;
    serveBuilder((b) => (body = b));
    const user = userEvent.setup();
    render(<BuilderTab />, { wrapper: Wrap });

    await chooseSource(user, "Attendance");
    await openPeriod(user);
    await user.click(screen.getByRole("option", { name: "Last 12 months" }));

    await chooseSource(user, "Monthly timesheet");
    expect(
      screen.queryByRole("combobox", { name: /value: period/i }),
    ).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /Run Preview/ }));
    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ source: "timesheet" });
    expect(body).not.toHaveProperty("filters");
  });
});

describe("Reports builder, column order", () => {
  it("does not make the preview out of date", async () => {
    serveBuilder(() => {});
    const user = userEvent.setup();
    render(<BuilderTab />, { wrapper: Wrap });

    // Name every column, in the source's order, then preview.
    await user.click(await screen.findByRole("button", { name: "All" }));
    await user.click(screen.getByRole("button", { name: /Run Preview/ }));
    expect(
      await screen.findByRole("button", { name: /Save as Template/ }),
    ).toBeEnabled();

    // Untick Name, then tick it again: the same set of columns, Name last.
    await user.click(screen.getByRole("checkbox", { name: "Name" }));
    expect(screen.getByRole("status")).toBeInTheDocument();
    await user.click(screen.getByRole("checkbox", { name: "Name" }));

    expect(screen.queryByRole("status")).not.toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /Save as Template/ }),
    ).toBeEnabled();
  });
});
