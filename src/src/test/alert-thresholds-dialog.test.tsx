import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { AlertThresholdsDialog } from "@/features/dashboard/components/alert-thresholds-dialog";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function renderDialog(onClose = vi.fn()) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <AlertThresholdsDialog open onClose={onClose} />
    </QueryClientProvider>,
  );
}

describe("<AlertThresholdsDialog>", () => {
  it("shows an empty state with no rules configured", async () => {
    server.use(
      http.get("*/dashboard/alert-thresholds", () =>
        HttpResponse.json({ thresholds: [] }),
      ),
    );
    renderDialog();

    expect(await screen.findByText("No alert rules yet")).toBeInTheDocument();
  });

  it("lists an existing rule with its metric, operator, and value", async () => {
    server.use(
      http.get("*/dashboard/alert-thresholds", () =>
        HttpResponse.json({
          thresholds: [
            {
              public_id: "01HZALERT0000000000000001",
              metric: "turnover_rate",
              operator: "gt",
              threshold_value: 5,
              severity: "warning",
            },
          ],
        }),
      ),
    );
    renderDialog();

    expect(await screen.findByText(/Turnover rate/)).toBeInTheDocument();
    expect(screen.getByText(/exceeds 5/)).toBeInTheDocument();
  });

  it("creates a new rule with the entered value", async () => {
    server.use(
      http.get("*/dashboard/alert-thresholds", () =>
        HttpResponse.json({ thresholds: [] }),
      ),
    );

    let posted: Record<string, unknown> | null = null;
    server.use(
      http.post("*/dashboard/alert-thresholds", async ({ request }) => {
        posted = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(
          {
            public_id: "01HZALERT0000000000000002",
            ...posted,
          },
          { status: 201 },
        );
      }),
    );

    renderDialog();
    await screen.findByText("No alert rules yet");

    const valueInput = screen.getByPlaceholderText("5");
    fireEvent.change(valueInput, { target: { value: "12" } });
    fireEvent.click(screen.getByText("Add rule"));

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted).toMatchObject({
      metric: "turnover_rate",
      operator: "gt",
      threshold_value: 12,
      severity: "warning",
    });
  });
});
