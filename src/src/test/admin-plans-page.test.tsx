import { describe, expect, it, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import AdminPlansPage from "@/app/(dashboard)/admin/plans/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const PLAN = {
  public_id: "PLAN1",
  name: "Starter",
  slug: "starter",
  description: null,
  description_am: null,
  price_cents: 99900,
  currency: "ETB",
  billing_interval: "monthly",
  max_employees: 25,
  max_branches: null,
  max_devices: null,
  features: ["attendance", "leave"],
  marketing_features: [],
  marketing_features_am: [],
  is_active: true,
  is_public: true,
  is_popular: false,
  sort_order: 1,
};

function serve() {
  const sent: { method: string; body: Record<string, unknown> }[] = [];
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Platform", role: "super_admin" },
        tenant: null,
        permissions: [],
      }),
    ),
    http.get("*/api/v1/admin/plans", () => HttpResponse.json({ data: [PLAN] })),
    http.put("*/api/v1/admin/plans/:id", async ({ request }) => {
      const body = (await request.json()) as Record<string, unknown>;
      sent.push({ method: "PUT", body });
      return HttpResponse.json({ data: { ...PLAN, ...body } });
    }),
    http.post("*/api/v1/admin/plans", async ({ request }) => {
      const body = (await request.json()) as Record<string, unknown>;
      sent.push({ method: "POST", body });
      return HttpResponse.json({ data: { ...PLAN, ...body } }, { status: 201 });
    }),
  );
  return sent;
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <AdminPlansPage />
    </QueryClientProvider>,
  );
}

// `POST /admin/plans` had no screen, and neither creating nor editing could
// set a plan's features — the gates on payroll, reports, API access and the
// rest (audit N101).
describe("platform plan catalogue", () => {
  it("edits which features a plan includes", async () => {
    const sent = serve();
    renderPage();
    const user = userEvent.setup();

    await user.click(await screen.findByRole("checkbox", { name: "Payroll" }));
    await user.click(screen.getByRole("button", { name: /^Save/ }));

    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].body.features).toEqual(["attendance", "leave", "payroll"]);
  });

  it("creates a plan hidden from the pricing page", async () => {
    const sent = serve();
    renderPage();
    const user = userEvent.setup();

    await user.click(await screen.findByRole("button", { name: /New plan/ }));
    const card = screen
      .getAllByText("New plan")
      .map((el) => el.closest("[class*='rounded']"))
      .find(
        (el): el is HTMLElement =>
          el instanceof HTMLElement && !!within(el).queryByLabelText("Slug"),
      );
    expect(card).toBeDefined();
    const scope = within(card as HTMLElement);
    await user.type(scope.getByLabelText("Name"), "Growth");
    await user.type(scope.getByLabelText("Slug"), "Growth");
    await user.type(scope.getByLabelText(/Price per month/), "2499.50");
    await user.click(scope.getByRole("button", { name: /Create plan/ }));

    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0]).toMatchObject({
      method: "POST",
      body: {
        name: "Growth",
        slug: "growth",
        price_cents: 249950,
        is_public: false,
      },
    });
    expect(sent[0].body.features).toEqual(
      expect.arrayContaining(["employee_management", "attendance", "leave"]),
    );
  });
});
