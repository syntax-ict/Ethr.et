import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { DashboardDigestDialog } from "@/features/dashboard/components/dashboard-digest-dialog";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function renderDialog(onClose = vi.fn()) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <DashboardDigestDialog open onClose={onClose} />
    </QueryClientProvider>,
  );
}

describe("<DashboardDigestDialog>", () => {
  it("shows an empty state with no digests scheduled", async () => {
    server.use(
      http.get("*/dashboard/digests", () => HttpResponse.json({ digests: [] })),
    );
    renderDialog();

    expect(await screen.findByText("No digests scheduled")).toBeInTheDocument();
  });

  it("lists an active digest with its frequency and recipient count", async () => {
    server.use(
      http.get("*/dashboard/digests", () =>
        HttpResponse.json({
          digests: [
            {
              public_id: "01HZDIGEST0000000000000001",
              branch_name: null,
              frequency: "weekly",
              recipients: ["ceo@example.com"],
              next_run_at: "2026-08-25T06:00:00Z",
              last_run_at: null,
            },
          ],
        }),
      ),
    );
    renderDialog();

    expect(await screen.findByText("weekly")).toBeInTheDocument();
  });

  it("schedules a new digest with the entered recipient", async () => {
    server.use(
      http.get("*/dashboard/digests", () => HttpResponse.json({ digests: [] })),
    );

    let posted: Record<string, unknown> | null = null;
    server.use(
      http.post("*/dashboard/digests", async ({ request }) => {
        posted = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(
          {
            public_id: "01HZDIGEST0000000000000002",
            branch_name: null,
            frequency: posted.frequency,
            recipients: posted.recipients,
            next_run_at: "2026-08-25T06:00:00Z",
            last_run_at: null,
          },
          { status: 201 },
        );
      }),
    );

    renderDialog();
    await screen.findByText("No digests scheduled");

    const emailInput = screen.getByPlaceholderText("ceo@example.com");
    fireEvent.change(emailInput, { target: { value: "hr@example.com" } });
    fireEvent.click(screen.getByText("Add"));

    expect(await screen.findByText("hr@example.com")).toBeInTheDocument();

    fireEvent.click(screen.getByText("Schedule digest"));

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted).toMatchObject({
      frequency: "weekly",
      recipients: ["hr@example.com"],
    });
  });
});
