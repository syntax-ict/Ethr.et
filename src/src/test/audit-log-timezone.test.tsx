import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { AuditLogExplorer } from "@/components/shared/audit-log-explorer";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/**
 * The audit trail is the record of who did what and when, and "when" is the
 * whole point of it. It rendered in the browser's timezone until 2026-09-16,
 * which meant the same entry read differently depending on the machine it was
 * opened on — and an append-only log whose timestamps move is not evidence of
 * anything.
 *
 * These assert against a tenant zone that is *not* the host's. The machine this
 * was written on is Africa/Nairobi (UTC+3, the same offset as Addis), so an
 * Addis-only assertion could pass by coincidence; Europe/London in August is
 * UTC+1 and cannot.
 */

const WRITE_AT = "2026-08-20T09:00:00.000Z";

function mockMe(timezone: string | null) {
  server.use(
    http.get("*/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "01HZUSER", name: "Abebe", role: "hr_admin" },
        permissions: [],
        tenant: {
          public_id: "01HZTENANT",
          name: "Acme",
          subdomain: "acme",
          status: "active",
          timezone,
        },
      }),
    ),
  );
}

function mockLogs() {
  server.use(
    http.get("*/audit-logs", () =>
      HttpResponse.json({
        data: [
          {
            created_at: WRITE_AT,
            action: "employee.updated",
            entity_type: "Employee",
            entity_id: "01HZEMPLOYEE0000000000001",
            user: { name: "Abebe Kebede" },
            ip_address: "196.189.0.1",
          },
        ],
        meta: { current_page: 1, last_page: 1, total: 1 },
      }),
    ),
  );
}

function renderExplorer() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      {/* The explorer's date filters are dual-calendar inputs, which read the
          Gregorian/Ethiopian preference from context. */}
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

describe("audit trail timestamps", () => {
  it("reads an entry in the tenant's zone, not the host's", async () => {
    // 09:00Z is 12:00 in Addis.
    mockMe("Africa/Addis_Ababa");
    mockLogs();
    renderExplorer();

    expect(await screen.findByText(/20 Aug 2026, 12:00/)).toBeInTheDocument();
  });

  it("follows a tenant on a DST-observing zone, in summer", async () => {
    // 09:00Z is 10:00 in London in August, because BST is UTC+1. A fixed +3
    // offset would say 12:00 and a host-clock rendering would too, since this
    // machine is UTC+3 — so this is the assertion that has teeth.
    mockMe("Europe/London");
    mockLogs();
    renderExplorer();

    expect(await screen.findByText(/20 Aug 2026, 10:00/)).toBeInTheDocument();
  });

  it("falls back to Addis for a tenant with no zone configured", async () => {
    mockMe(null);
    mockLogs();
    renderExplorer();

    expect(await screen.findByText(/20 Aug 2026, 12:00/)).toBeInTheDocument();
  });
});
