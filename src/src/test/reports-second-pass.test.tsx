import { afterEach, beforeEach, describe, it, expect, vi } from "vitest";
import { act, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { toast } from "sonner";
import { server } from "./msw/server";
import { BuilderTab } from "@/features/reports/components/builder-tab";
import { PreviewResult } from "@/features/reports/components/preview-result";
import { SavedTab } from "@/features/reports/components/saved-tab";
import { ScheduledTab } from "@/features/reports/components/scheduled-tab";
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
    leave: {
      label: "Leave Balances",
      fields: ["employee_name", "year"],
      filters: ["year"],
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

async function fieldOptions(
  user: ReturnType<typeof userEvent.setup>,
  n: number,
) {
  await user.click(
    screen.getAllByRole("combobox", { name: "Filter field" })[n],
  );
  const listbox = await screen.findByRole("listbox");
  const names = within(listbox)
    .getAllByRole("option")
    .map((o) => o.textContent);
  await user.keyboard("{Escape}");
  return names;
}

describe("Reports builder, the period filter", () => {
  it("is a select of the eight keys, and replaces From and To", async () => {
    let body: Record<string, unknown> | null = null;
    serveBuilder((b) => (body = b));
    const user = userEvent.setup();
    render(<BuilderTab />, { wrapper: Wrap });

    await chooseSource(user, "Attendance");
    await user.click(screen.getByRole("button", { name: "Add filter" }));
    // The first row is From; switch it to Period.
    await user.click(screen.getByRole("combobox", { name: "Filter field" }));
    await user.click(await screen.findByRole("option", { name: "Period" }));

    await user.click(screen.getByRole("combobox", { name: /value: period/i }));
    const listbox = await screen.findByRole("listbox");
    expect(
      within(listbox)
        .getAllByRole("option")
        .map((o) => o.textContent),
    ).toEqual([
      "This month",
      "Last month",
      "Last 3 months",
      "Last 12 months",
      "Yesterday",
      "Last 7 days",
      "This fiscal year",
      "Last fiscal year",
    ]);
    await user.click(screen.getByRole("option", { name: "Last month" }));

    // With a period present, From and To are not offered.
    await user.click(screen.getByRole("button", { name: "Add filter" }));
    expect(await fieldOptions(user, 1)).toEqual(["Period", "Status"]);

    await user.click(screen.getByRole("button", { name: /Run Preview/ }));
    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({
      source: "attendance",
      filters: { period: "last_month" },
    });
  });

  it("does not offer Period beside From or To", async () => {
    serveBuilder(() => {});
    const user = userEvent.setup();
    render(<BuilderTab />, { wrapper: Wrap });

    await chooseSource(user, "Attendance");
    await user.click(screen.getByRole("button", { name: "Add filter" }));
    await user.click(screen.getByRole("button", { name: "Add filter" }));
    expect(await fieldOptions(user, 1)).toEqual(["From", "To", "Status"]);
  });

  it("sends the year as a number", async () => {
    let body: Record<string, unknown> | null = null;
    serveBuilder((b) => (body = b));
    const user = userEvent.setup();
    render(<BuilderTab />, { wrapper: Wrap });

    await chooseSource(user, "Leave balances");
    await user.click(screen.getByRole("button", { name: "Add filter" }));
    await user.type(screen.getByRole("spinbutton"), "2026");
    await user.click(screen.getByRole("button", { name: /Run Preview/ }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ filters: { year: 2026 } });
  });
});

describe("Reports, a result that was cut short", () => {
  const cut = result({
    truncated: true,
    matched: 2500,
    total: 3,
    data: [{ name: "A" }, { name: "B" }, { name: "C" }],
  });

  it("says how many of how many rows are shown", () => {
    render(<PreviewResult result={cut} />, { wrapper: Wrap });
    expect(
      screen.getByText(/^Showing the newest 3 of 2500 rows/),
    ).toBeInTheDocument();
  });

  it("says so in Amharic", () => {
    act(() => setLocale("am"));
    render(<PreviewResult result={cut} />, { wrapper: Wrap });
    const note = am["reports_page.truncated_of"]
      .replace(":matched", "2500")
      .replace(":shown", "3");
    expect(screen.getByText(note)).toBeInTheDocument();
  });

  it("warns when the group totals are partial", () => {
    render(
      <PreviewResult
        result={result({
          summary: {
            grouped_by: "department",
            groups: { A: 1 },
            complete: false,
          },
        })}
      />,
      { wrapper: Wrap },
    );
    expect(screen.getByText(/group totals are partial/)).toBeInTheDocument();
  });

  it("prints the dates the server applied", () => {
    render(
      <PreviewResult
        result={result({ period: { from: "2026-10-11", to: "2026-11-09" } })}
        config={{ source: "attendance", filters: { period: "last_month" } }}
      />,
      { wrapper: Wrap },
    );
    expect(screen.getByTestId("report-print-heading")).toHaveTextContent(
      "Period: Tikimt 1, 2019 to Tikimt 30, 2019",
    );
  });

  it("is written under the rows of the downloaded file", async () => {
    serveBuilder(() => {}, cut);
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
      "Showing the newest 3 of 2500 rows. Narrow the dates or filters to see the rest.",
    ]);
  });
});

describe("Reports, scheduled tab, a failed run", () => {
  it("shows the reason in words, never the code", async () => {
    server.use(
      http.get("*/api/v1/reports/scheduled", () =>
        HttpResponse.json({
          schedules: [
            {
              public_id: "s1",
              report_name: "Lateness",
              frequency: "daily",
              recipients: ["a@example.com"],
              next_run_at: null,
              last_run_at: null,
              last_error: "creator_cannot_run",
            },
            {
              public_id: "s2",
              report_name: "Headcount",
              frequency: "daily",
              recipients: ["a@example.com"],
              next_run_at: null,
              last_run_at: null,
              last_error: null,
            },
            {
              public_id: "s3",
              report_name: "Overtime",
              frequency: "daily",
              recipients: ["a@example.com"],
              next_run_at: null,
              last_run_at: null,
              last_error: "error",
            },
          ],
        }),
      ),
    );
    render(<ScheduledTab />, { wrapper: Wrap });

    await screen.findByText("Lateness");
    const alerts = screen.getAllByRole("alert");
    expect(alerts).toHaveLength(2);
    expect(alerts[0]).toHaveTextContent("Last run failed");
    expect(alerts[0]).toHaveTextContent(
      "The person who saved this report can no longer run it.",
    );
    expect(alerts[1]).toHaveTextContent(
      "The report could not be produced. Run it from the Saved tab to see the problem.",
    );
    // The code is never shown.
    expect(screen.queryByText(/creator_cannot_run/)).not.toBeInTheDocument();
  });
});

describe("Reports, saved tab, scheduling", () => {
  const saved = (mine: boolean, config: object = { source: "attendance" }) =>
    server.use(
      http.get("*/api/v1/reports/saved", () =>
        HttpResponse.json({
          reports: [
            {
              public_id: "rep_1",
              name: "Late list",
              config,
              created_at: "2026-10-01T08:00:00Z",
              mine,
            },
          ],
        }),
      ),
    );

  it("offers Schedule only to the person who saved the report", async () => {
    saved(false);
    render(<SavedTab />, { wrapper: Wrap });
    await screen.findByText("Late list");
    expect(
      screen.queryByRole("button", { name: /Schedule/ }),
    ).not.toBeInTheDocument();
  });

  it("explains a refusal", async () => {
    saved(true);
    server.use(
      http.post("*/api/v1/reports/schedule", () =>
        HttpResponse.json({ detail: "Forbidden" }, { status: 403 }),
      ),
    );
    const user = userEvent.setup();
    render(<SavedTab />, { wrapper: Wrap });

    await user.click(await screen.findByRole("button", { name: /Schedule/ }));
    await user.type(screen.getByPlaceholderText("hr@example.com"), "a@b.et");
    await user.click(screen.getByRole("button", { name: "Add" }));
    const dialog = screen.getByRole("dialog");
    await user.click(
      within(dialog)
        .getAllByRole("button", { name: /Schedule/ })
        .at(-1)!,
    );

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        "Only the person who saved a report can schedule it.",
      ),
    );
  });

  it("warns that fixed dates are sent unchanged, but not for a period", async () => {
    saved(true, {
      source: "attendance",
      filters: { from: "2026-10-01", to: "2026-10-31" },
    });
    const user = userEvent.setup();
    const { unmount } = render(<SavedTab />, { wrapper: Wrap });
    await user.click(await screen.findByRole("button", { name: /Schedule/ }));
    expect(screen.getByRole("note")).toHaveTextContent("fixed dates");
    unmount();

    saved(true, { source: "attendance", filters: { period: "last_month" } });
    render(<SavedTab />, { wrapper: Wrap });
    await user.click(await screen.findByRole("button", { name: /Schedule/ }));
    expect(screen.queryByRole("note")).not.toBeInTheDocument();
  });

  it("gives the fixed-dates notice in Amharic", async () => {
    act(() => setLocale("am"));
    saved(true, { source: "attendance", filters: { from: "2026-10-01" } });
    const user = userEvent.setup();
    render(<SavedTab />, { wrapper: Wrap });
    await user.click(
      await screen.findByRole("button", { name: am["reports_page.schedule"] }),
    );
    expect(screen.getByRole("note")).toHaveTextContent(
      am["reports_page.fixed_dates_notice"],
    );
  });
});
