import { afterEach, beforeEach, describe, it, expect, vi } from "vitest";
import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { toast } from "sonner";
import { server } from "./msw/server";
import { BuilderTab } from "@/features/reports/components/builder-tab";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { setLocale } from "@/lib/i18n/translations";

const saved = vi.hoisted(() => ({ blob: vi.fn() }));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

vi.mock("@/lib/utils/csv-export", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/utils/csv-export")>()),
  saveBlob: saved.blob,
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

const SOURCES = {
  sources: {
    attendance: {
      label: "Attendance",
      fields: ["employee_name", "date", "status"],
      filters: ["from", "to", "period", "status"],
    },
  },
};

function serve(onExport: (body: Record<string, unknown>) => void) {
  server.use(
    http.get("*/api/v1/reports/sources", () => HttpResponse.json(SOURCES)),
    http.post("*/api/v1/reports/generate", () =>
      HttpResponse.json({
        source: "attendance",
        total: 1,
        matched: 1,
        truncated: false,
        period: null,
        data: [{ employee_name: "Abebe Kebede", date: "2026-10-12" }],
        summary: [],
      }),
    ),
    http.post("*/api/v1/reports/export", async ({ request }) => {
      onExport((await request.json()) as Record<string, unknown>);
      return new HttpResponse(new Blob(["PK"]), {
        headers: {
          "Content-Type":
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        },
      });
    }),
  );
}

async function preview(user: ReturnType<typeof userEvent.setup>) {
  await user.click(
    await screen.findByRole("combobox", { name: "Data Source" }),
  );
  await user.click(await screen.findByRole("option", { name: "Attendance" }));
  await user.click(screen.getByRole("button", { name: /Run Preview/ }));
  await screen.findByRole("button", { name: "Download Excel" });
}

describe("Reports, Excel", () => {
  it("asks the server for the report as a workbook, with the settings of the preview", async () => {
    let body: Record<string, unknown> | null = null;
    serve((b) => (body = b));
    const user = userEvent.setup();
    render(<BuilderTab />, { wrapper: Wrap });
    await preview(user);

    await user.click(screen.getByRole("button", { name: "Download Excel" }));

    await waitFor(() => expect(saved.blob).toHaveBeenCalled());
    expect(body).toMatchObject({ source: "attendance", format: "xlsx" });
    expect(saved.blob.mock.calls[0][0]).toMatch(
      /^attendance-report-\d{4}-\d{2}-\d{2}\.xlsx$/,
    );
  });

  it("is offered in Amharic and says when it fails", async () => {
    serve(() => {});
    server.use(
      http.post("*/api/v1/reports/export", () =>
        HttpResponse.json({ status: 500 }, { status: 500 }),
      ),
    );
    const user = userEvent.setup();
    render(<BuilderTab />, { wrapper: Wrap });
    await preview(user);

    act(() => setLocale("am"));
    await user.click(await screen.findByRole("button", { name: "Excel አውርድ" }));

    await waitFor(() => expect(toast.error).toHaveBeenCalled());
    expect(saved.blob).not.toHaveBeenCalled();
  });
});
