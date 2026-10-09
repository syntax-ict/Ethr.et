import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import WebhooksPage from "@/app/(dashboard)/settings/webhooks/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/** One row of `WebhookController::index`'s `webhooks` array. */
const WEBHOOK = {
  public_id: "WH1",
  url: "https://erp.example.et/hooks/ethr",
  events: ["payroll.approved"],
  is_active: true,
  failure_count: 0,
  last_triggered_at: null,
  created_at: "2026-09-01T06:00:00Z",
};

/**
 * Every event name the API dispatches — the `$this->webhook(...)` call sites
 * in EmployeeController, LeaveRequestController, PayrollController and
 * ProcessPayrollJob.
 */
const DISPATCHED = [
  "employee.created",
  "employee.updated",
  "leave.requested",
  "leave.approved",
  "leave.rejected",
  "payroll.processed",
  "payroll.approved",
  "payroll.voided",
  "payroll.reprocessed",
];

function me(permissions: string[], role = "tenant_admin") {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Test User", role },
      tenant: { public_id: "T1", name: "Demo", timezone: "Africa/Addis_Ababa" },
      permissions,
    }),
  );
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<WebhooksPage />, { wrapper: Wrapper });
}

describe("<WebhooksPage>", () => {
  it("offers exactly the events the API dispatches", async () => {
    // It offered five events nothing sends (attendance.*, device.*,
    // employee.transitioned) — a webhook on those is Active and never fires —
    // and left out payroll.voided and payroll.reprocessed, which do fire.
    server.use(
      me(["webhook.manage"]),
      http.get("*/api/v1/webhooks", () => HttpResponse.json({ webhooks: [] })),
    );
    const user = userEvent.setup();
    renderPage();

    await user.click(
      await screen.findByRole("button", { name: /Add Webhook/ }),
    );
    const events = await screen.findByRole("group", { name: "Events" });
    const offered = within(events)
      .getAllByRole("checkbox")
      .map((box) => box.closest("label")!.textContent);

    expect(offered).toEqual(DISPATCHED);
  });

  it("deletes a webhook only after it is confirmed", async () => {
    let deletes = 0;
    server.use(
      me(["webhook.manage"]),
      http.get("*/api/v1/webhooks", () =>
        HttpResponse.json({ webhooks: deletes ? [] : [WEBHOOK] }),
      ),
      http.delete("*/api/v1/webhooks/WH1", () => {
        deletes++;
        return new HttpResponse(null, { status: 204 });
      }),
    );
    const user = userEvent.setup();
    renderPage();

    await screen.findByText(WEBHOOK.url);
    await user.click(screen.getByRole("button", { name: "Delete" }));

    const dialog = await screen.findByRole("dialog");
    expect(deletes).toBe(0);

    await user.click(within(dialog).getByRole("button", { name: "Delete" }));
    await waitFor(() => expect(deletes).toBe(1));
  });

  it("shows the signing secret once, with a named copy button", async () => {
    server.use(
      me(["webhook.manage"]),
      http.get("*/api/v1/webhooks", () => HttpResponse.json({ webhooks: [] })),
      http.post("*/api/v1/webhooks", () =>
        HttpResponse.json(
          {
            public_id: "WH2",
            url: "https://erp.example.et/hooks/new",
            secret: "whsec_plainvalue",
            events: ["payroll.voided"],
            is_active: true,
            created_at: "2026-10-01T06:00:00Z",
          },
          { status: 201 },
        ),
      ),
    );
    const user = userEvent.setup();
    renderPage();

    await user.click(
      await screen.findByRole("button", { name: /Add Webhook/ }),
    );
    await user.type(
      await screen.findByRole("textbox", { name: /URL/ }),
      "https://erp.example.et/hooks/new",
    );
    await user.click(screen.getByRole("checkbox", { name: "payroll.voided" }));
    await user.click(screen.getByRole("button", { name: "Create" }));

    expect(await screen.findByText("whsec_plainvalue")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Copy" })).toBeInTheDocument();
  });
});

describe("<WebhooksPage> — recovering a webhook", () => {
  // The delivery job switches a webhook off after repeated failures. The page
  // had no way to switch it back on or change it, so recovering meant delete
  // and recreate, and a new signing secret for the integrator (audit N71).
  it("switches a disabled webhook back on", async () => {
    let body: unknown = null;
    server.use(
      me(["webhook.manage"]),
      http.get("*/api/v1/webhooks", () =>
        HttpResponse.json({
          webhooks: [{ ...WEBHOOK, is_active: false, failure_count: 10 }],
        }),
      ),
      http.put("*/api/v1/webhooks/WH1", async ({ request }) => {
        body = await request.json();
        return HttpResponse.json({ ...WEBHOOK, is_active: true });
      }),
    );
    const user = userEvent.setup();
    renderPage();

    await user.click(
      await screen.findByRole("switch", { name: /deliver to this webhook/i }),
    );

    await waitFor(() => expect(body).toEqual({ is_active: true }));
  });

  it("edits the URL and events in place", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      me(["webhook.manage"]),
      http.get("*/api/v1/webhooks", () =>
        HttpResponse.json({ webhooks: [WEBHOOK] }),
      ),
      http.put("*/api/v1/webhooks/WH1", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(WEBHOOK);
      }),
    );
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole("button", { name: "Edit" }));
    const dialog = await screen.findByRole("dialog");
    const url = within(dialog).getByRole("textbox");
    expect(url).toHaveValue(WEBHOOK.url);
    await user.clear(url);
    await user.type(url, "https://erp.example.et/hooks/v2");
    await user.click(within(dialog).getByRole("button", { name: "Save" }));

    await waitFor(() =>
      expect(body).toEqual({
        url: "https://erp.example.et/hooks/v2",
        events: ["payroll.approved"],
      }),
    );
  });
});
