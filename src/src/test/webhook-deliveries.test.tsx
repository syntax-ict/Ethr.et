import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { WebhookDeliveriesDialog } from "@/features/webhooks/webhook-deliveries-dialog";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

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
    server.use(
      http.get("*/webhooks/:id/deliveries", () =>
        HttpResponse.json({ deliveries: [] }),
      ),
    );

    renderDialog();

    expect(await screen.findByText("No deliveries yet")).toBeInTheDocument();
  });
});
