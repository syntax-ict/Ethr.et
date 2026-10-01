import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import type { components } from "@/api/generated";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { saveCsv } from "@/lib/utils/csv-export";
import { AuditLogExplorer } from "@/components/shared/audit-log-explorer";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

vi.mock("@/lib/utils/csv-export", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/utils/csv-export")>()),
  saveCsv: vi.fn(),
}));

type AuditLogResource = components["schemas"]["AuditLogResource"];

function entry(action: string): AuditLogResource {
  return {
    action,
    auditable_type: "App\\Models\\Employee",
    auditable_id: 7,
    user_id: 3,
    data: null,
    ip_address: "196.189.0.1",
    user_agent: null,
    created_at: "2026-09-20T09:00:00Z",
  };
}

/** Two pages, whatever the page size asked for. */
function twoPages() {
  const asked: Array<{ page: string; perPage: string | null }> = [];
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Admin", role: "tenant_admin" },
        permissions: ["settings.manage"],
        tenant: { public_id: "T1", name: "Acme", timezone: null },
      }),
    ),
    http.get("*/api/v1/audit-logs", ({ request }) => {
      const params = new URL(request.url).searchParams;
      const page = params.get("page") ?? "1";
      asked.push({ page, perPage: params.get("per_page") });
      return HttpResponse.json({
        data: [entry(page === "1" ? "employee.created" : "payroll.approved")],
        links: { first: null, last: null, prev: null, next: null },
        meta: {
          current_page: Number(page),
          last_page: 2,
          per_page: 50,
          total: 2,
          from: Number(page),
          to: Number(page),
        },
      });
    }),
  );
  return asked;
}

function renderExplorer() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <CalendarProvider>
        <AuditLogExplorer
          endpoint="/audit-logs"
          queryKey="audit-logs"
          title="Audit log"
          description="Who changed what"
        />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

describe("Audit log export", () => {
  it("exports every matching entry, not the page on screen", async () => {
    twoPages();
    renderExplorer();

    expect(await screen.findByText("employee.created")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: /Export CSV/ }));

    await waitFor(() => expect(saveCsv).toHaveBeenCalled());
    const csv = vi.mocked(saveCsv).mock.calls[0][1];
    expect(csv).toContain("employee.created");
    // On page 2, never displayed.
    expect(csv).toContain("payroll.approved");
  });

  it("labels its filters", async () => {
    twoPages();
    renderExplorer();

    expect(
      await screen.findByRole("textbox", { name: "Action" }),
    ).toBeInTheDocument();
  });
});
