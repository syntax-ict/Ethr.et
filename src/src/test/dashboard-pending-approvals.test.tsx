import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { toast } from "sonner";
import { server } from "./msw/server";
import { PendingApprovalsPanel } from "@/features/dashboard/components/pending-approvals";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/**
 * The dashboard's Pending Approvals panel decides requests where they are
 * seen (dashboard review, 2026-11-03, PR 6). It reads the same queue as the
 * Approvals page and sends the same batch, so these tests check what it
 * lists, what it sends, and that an employee's dashboard asks for nothing.
 */
function me(role: string, permissions: string[] = []) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Test", role },
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
        permissions,
      }),
    ),
  );
}

/** Shaped like the `items` ApprovalController::pending() builds. */
function item(n: number, over: Record<string, unknown> = {}) {
  return {
    type: "leave",
    public_id: `01HZLEAVE000000000000000${n}`,
    employee_name: `Person ${n}`,
    employee_public_id: `01HZEMP00000000000000000${n}`,
    summary: `Annual Leave: Oct 0${n} - Oct 0${n}`,
    submitted_at: "2026-10-01T06:00:00Z",
    step: 1,
    steps: 1,
    ...over,
  };
}

function serve(items: ReturnType<typeof item>[]) {
  const hits: string[] = [];
  const batches: unknown[] = [];
  server.use(
    http.get("*/api/v1/approvals/pending", ({ request }) => {
      hits.push(request.url);
      return HttpResponse.json({ items, total: items.length });
    }),
    http.post("*/api/v1/approvals/batch", async ({ request }) => {
      const body = (await request.json()) as {
        actions: { public_id: string }[];
      };
      batches.push(body);
      return HttpResponse.json({
        results: body.actions.map((a) => ({
          public_id: a.public_id,
          status: "approved",
        })),
      });
    }),
  );
  return { hits, batches };
}

function renderPanel() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <PendingApprovalsPanel />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(toast.success).mockClear();
  vi.mocked(toast.error).mockClear();
});

describe("<PendingApprovalsPanel>", () => {
  it("asks for nothing and draws nothing for an employee", async () => {
    me("employee");
    const sent = serve([item(1)]);
    const { container } = renderPanel();

    await new Promise((r) => setTimeout(r, 200));
    expect(container).toBeEmptyDOMElement();
    expect(sent.hits).toEqual([]);
  });

  it("lists the newest three with their count, and links to the rest", async () => {
    me("supervisor", ["leave.viewTeam", "leave.approve"]);
    serve([item(1), item(2), item(3), item(4), item(5)]);
    renderPanel();

    expect(await screen.findByText("Person 1")).toBeInTheDocument();
    expect(screen.getByText("Person 3")).toBeInTheDocument();
    expect(screen.queryByText("Person 4")).toBeNull();
    expect(screen.getByText("5")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /see 2 more/i })).toHaveAttribute(
      "href",
      "/approvals",
    );
    expect(screen.getAllByRole("button", { name: /approve/i })).toHaveLength(3);
  });

  it("approves a request from its row and reports it done", async () => {
    me("supervisor", ["leave.viewTeam", "leave.approve"]);
    const sent = serve([item(1), item(2)]);
    const user = userEvent.setup();
    renderPanel();

    await screen.findByText("Person 2");
    await user.click(screen.getAllByRole("button", { name: /approve/i })[1]);

    await waitFor(() => expect(sent.batches).toHaveLength(1));
    expect(sent.batches[0]).toEqual({
      actions: [
        {
          type: "leave",
          public_id: "01HZLEAVE0000000000000002",
          action: "approve",
        },
      ],
    });
    await waitFor(() => expect(toast.success).toHaveBeenCalled());
  });

  it("asks for a reason before rejecting, and sends it with the request", async () => {
    me("supervisor", ["leave.viewTeam", "leave.approve"]);
    const sent = serve([item(1)]);
    const user = userEvent.setup();
    renderPanel();

    await screen.findByText("Person 1");
    await user.click(screen.getByRole("button", { name: /reject/i }));

    const dialog = await screen.findByRole("dialog");
    expect(dialog).toHaveTextContent(/shown to Person 1/);
    const confirm = screen
      .getAllByRole("button", { name: /^reject$/i })
      .at(-1)!;
    expect(confirm).toBeDisabled();
    await user.type(screen.getByRole("textbox"), "Cover is short that week");
    await user.click(confirm);

    await waitFor(() => expect(sent.batches).toHaveLength(1));
    expect(sent.batches[0]).toEqual({
      actions: [
        {
          type: "leave",
          public_id: "01HZLEAVE0000000000000001",
          action: "reject",
          reason: "Cover is short that week",
        },
      ],
    });
  });

  it("says when nothing is waiting", async () => {
    me("supervisor", ["leave.viewTeam", "leave.approve"]);
    serve([]);
    renderPanel();

    expect(await screen.findByText("No pending approvals")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /approve/i })).toBeNull();
  });
});
