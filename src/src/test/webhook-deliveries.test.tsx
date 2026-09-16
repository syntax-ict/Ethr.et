import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { WebhookDeliveriesDialog } from "@/features/webhooks/webhook-deliveries-dialog";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/**
 * The dialog reads the tenant's timezone through `useDateFormatters`, which
 * sits on the existing /auth/me query. Without a handler MSW's
 * `onUnhandledRequest: "error"` fails that request, the component silently
 * falls back to the default zone, and a timezone assertion would prove nothing.
 */
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

function renderDialog() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <WebhookDeliveriesDialog
        webhookId="01HZWEBHOOK000000000000001"
        webhookUrl="https://example.et/hooks/ethr"
        open
        onClose={vi.fn()}
      />
    </QueryClientProvider>,
  );
}

describe("<WebhookDeliveriesDialog>", () => {
  it("lists attempts with their event, status, and attempt number", async () => {
    mockMe(null);
    server.use(
      http.get("*/webhooks/:id/deliveries", () =>
        HttpResponse.json({
          deliveries: [
            {
              event: "employee.created",
              response_status: 200,
              attempt: 1,
              delivered_at: "2026-08-20T09:00:00+00:00",
              created_at: "2026-08-20T09:00:00+00:00",
            },
            {
              event: "leave.approved",
              response_status: 500,
              attempt: 3,
              delivered_at: null,
              created_at: "2026-08-20T10:00:00+00:00",
            },
          ],
        }),
      ),
    );

    renderDialog();

    expect(await screen.findByText("employee.created")).toBeInTheDocument();
    expect(screen.getByText("leave.approved")).toBeInTheDocument();
    expect(screen.getByText("200")).toBeInTheDocument();
    expect(screen.getByText("500")).toBeInTheDocument();
    expect(screen.getByText("3")).toBeInTheDocument();
  });

  it("distinguishes a connection failure from an HTTP error response", async () => {
    mockMe(null);
    server.use(
      http.get("*/webhooks/:id/deliveries", () =>
        HttpResponse.json({
          deliveries: [
            {
              event: "payroll.processed",
              response_status: null,
              attempt: 2,
              delivered_at: null,
              created_at: "2026-08-20T11:00:00+00:00",
            },
          ],
        }),
      ),
    );

    renderDialog();

    // A null status means nothing ever answered — not a 500 from the endpoint.
    expect(await screen.findByText("No response")).toBeInTheDocument();
  });

  it("shows an empty state when the endpoint has never been called", async () => {
    mockMe(null);
    server.use(
      http.get("*/webhooks/:id/deliveries", () =>
        HttpResponse.json({ deliveries: [] }),
      ),
    );

    renderDialog();

    expect(await screen.findByText("No deliveries yet")).toBeInTheDocument();
  });
});

describe("<WebhookDeliveriesDialog> delivery times", () => {
  function mockOneDelivery() {
    server.use(
      http.get("*/webhooks/:id/deliveries", () =>
        HttpResponse.json({
          deliveries: [
            {
              event: "employee.created",
              response_status: 200,
              attempt: 1,
              delivered_at: "2026-08-20T09:00:00.000Z",
              created_at: "2026-08-20T09:00:00.000Z",
            },
          ],
        }),
      ),
    );
  }

  it("stamps an attempt in the tenant's zone", async () => {
    // 09:00Z is 12:00 in Addis. A webhook that fired at 12:00 by the tenant's
    // clock is the only reading anyone debugging an integration can act on.
    mockMe("Africa/Addis_Ababa");
    mockOneDelivery();
    renderDialog();

    expect(await screen.findByText(/20 Aug 2026, 12:00/)).toBeInTheDocument();
  });

  it("follows a DST-observing tenant zone rather than a fixed offset", async () => {
    // 10:00 in London in August: BST is UTC+1. This machine is UTC+3, so a
    // host-clock rendering — the old behaviour — would say 12:00 here.
    mockMe("Europe/London");
    mockOneDelivery();
    renderDialog();

    expect(await screen.findByText(/20 Aug 2026, 10:00/)).toBeInTheDocument();
  });
});
