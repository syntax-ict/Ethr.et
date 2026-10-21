import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import ApprovalChainsPage from "@/app/(dashboard)/settings/approval-chains/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/** Shaped like `ApprovalWorkflowResource`. */
const CHAIN = {
  public_id: "01HZCHAIN0000000000000001",
  request_type: "leave",
  name: "Supervisor then HR",
  priority: 0,
  is_active: true,
  conditions: { department_ids: ["01HZDEPT00000000000000001"] },
  steps: [
    {
      position: 1,
      approver_kind: "supervisor",
      approver_ref: null,
      sla_hours: 48,
    },
    {
      position: 2,
      approver_kind: "role",
      approver_ref: "hr_admin",
      sla_hours: 48,
    },
  ],
  created_at: "2026-10-21T08:00:00+00:00",
  updated_at: "2026-10-21T08:00:00+00:00",
};

function page<T>(data: T[]) {
  return HttpResponse.json({
    data,
    meta: { current_page: 1, last_page: 1, per_page: 100, total: data.length },
    links: { next: null, prev: null },
  });
}

function setup(chains: unknown[]) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Admin", role: "tenant_admin" },
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
        permissions: ["settings.manage", "org.viewAny"],
      }),
    ),
    http.get("*/api/v1/settings/approval-workflows", () =>
      HttpResponse.json(chains),
    ),
    http.get("*/api/v1/organization/departments", () =>
      page([{ public_id: "01HZDEPT00000000000000001", name: "Finance" }]),
    ),
    http.get("*/api/v1/organization/branches", () => page([])),
    http.get("*/api/v1/leave-types", () => page([])),
    http.get("*/api/v1/users", () => page([])),
  );

  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<ApprovalChainsPage />, { wrapper: Wrapper });
}

describe("<ApprovalChainsPage>", () => {
  it("lists a chain with its steps in order and who it covers", async () => {
    setup([CHAIN]);

    expect(await screen.findByText("Supervisor then HR")).toBeInTheDocument();
    expect(screen.getByText("Leave requests")).toBeInTheDocument();
    expect(screen.getByText("Their supervisor")).toBeInTheDocument();
    expect(
      screen.getByText("Anyone with a role: HR admin"),
    ).toBeInTheDocument();
    expect(await screen.findByText("Applies to: Finance")).toBeInTheDocument();
  });

  it("says every request is decided in one step when there is no chain", async () => {
    setup([]);

    expect(await screen.findByText("No approval chains")).toBeInTheDocument();
  });

  it("creates a chain with the steps in the order shown", async () => {
    const user = userEvent.setup();
    let body: unknown = null;
    server.use(
      http.post("*/api/v1/settings/approval-workflows", async ({ request }) => {
        body = await request.json();
        return HttpResponse.json(
          { ...CHAIN, name: "Two reviewers" },
          {
            status: 201,
          },
        );
      }),
    );
    setup([]);

    await user.click(
      (await screen.findAllByRole("button", { name: "New chain" }))[0],
    );
    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText(/Name/), "Two reviewers");
    await user.click(
      within(dialog).getByRole("button", { name: "Create chain" }),
    );

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({
      name: "Two reviewers",
      request_type: "leave",
      is_active: true,
      priority: 0,
      steps: [
        { approver_kind: "supervisor", approver_ref: null },
        { approver_kind: "role", approver_ref: "hr_admin" },
      ],
    });
  });

  it("asks before deleting a chain, then deletes it", async () => {
    const user = userEvent.setup();
    let deleted = false;
    server.use(
      http.delete("*/api/v1/settings/approval-workflows/:id", ({ params }) => {
        deleted = params.id === CHAIN.public_id;
        return new HttpResponse(null, { status: 204 });
      }),
    );
    setup([CHAIN]);

    await user.click(
      await screen.findByRole("button", { name: "Delete Supervisor then HR" }),
    );
    expect(deleted).toBe(false);

    const confirm = await screen.findByRole("dialog");
    await user.click(within(confirm).getByRole("button", { name: "Delete" }));

    await waitFor(() => expect(deleted).toBe(true));
  });
});
