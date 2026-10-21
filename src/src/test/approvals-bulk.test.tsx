import { afterEach, describe, it, expect, vi } from "vitest";
import {
  render,
  screen,
  fireEvent,
  waitFor,
  within,
} from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import ApprovalsPage from "@/app/(dashboard)/approvals/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const item = (
  type: "leave" | "correction" | "profile_update",
  id: string,
  name: string,
) => ({
  type,
  public_id: id,
  employee_name: name,
  employee_public_id: `EMP-${id}`,
  summary: `${type} request`,
  submitted_at: "2026-10-01T06:00:00Z",
  step: 1,
  steps: 1,
});

const ITEMS = [
  item("leave", "L1", "Abebe Kebede"),
  item("leave", "L2", "Sara Tesfaye"),
  item("correction", "C1", "Hana Girma"),
];

let batches: unknown[] = [];

function renderAt(search = "") {
  window.history.replaceState(null, "", `/approvals${search}`);
  batches = [];
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Supervisor", role: "supervisor" },
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
        permissions: ["leave.viewTeam", "leave.approve"],
      }),
    ),
    http.get("*/api/v1/approvals/pending", () =>
      HttpResponse.json({ items: ITEMS, total: ITEMS.length }),
    ),
    http.post("*/api/v1/approvals/batch", async ({ request }) => {
      const body = (await request.json()) as {
        actions: { public_id: string }[];
      };
      batches.push(body);
      return HttpResponse.json({
        results: body.actions.map((a) => ({
          public_id: a.public_id,
          status: "ok",
        })),
      });
    }),
  );
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <ApprovalsPage />
    </QueryClientProvider>,
  );
}

afterEach(() => {
  window.history.replaceState(null, "", "/");
});

// The queue mixed every kind of request and each had to be decided alone
// (FRONTEND_REDESIGN_PLAN.md, Phase 4).
describe("Approvals filter and bulk", () => {
  it("narrows the queue to one type from the address", async () => {
    renderAt("?type=correction");

    expect(await screen.findByText("Hana Girma")).toBeInTheDocument();
    expect(screen.queryByText("Abebe Kebede")).not.toBeInTheDocument();
    expect(screen.queryByText("Sara Tesfaye")).not.toBeInTheDocument();
  });

  it("approves every ticked request in one batch", async () => {
    renderAt();
    await screen.findByText("Abebe Kebede");

    fireEvent.click(
      screen.getByRole("checkbox", {
        name: "Select the request from Abebe Kebede",
      }),
    );
    fireEvent.click(
      screen.getByRole("checkbox", {
        name: "Select the request from Hana Girma",
      }),
    );
    const bar = screen.getByRole("region", { name: "Selected requests" });
    expect(within(bar).getByText("2 selected")).toBeInTheDocument();
    fireEvent.click(within(bar).getByRole("button", { name: "Approve 2" }));

    await waitFor(() => expect(batches).toHaveLength(1));
    expect(batches[0]).toEqual({
      actions: [
        { type: "leave", public_id: "L1", action: "approve" },
        { type: "correction", public_id: "C1", action: "approve" },
      ],
    });
  });

  it("rejects the selection with one reason, shown to each employee", async () => {
    renderAt("?type=leave");
    await screen.findByText("Abebe Kebede");

    fireEvent.click(screen.getByRole("checkbox", { name: /select all 2/i }));
    const bar = screen.getByRole("region", { name: "Selected requests" });
    fireEvent.click(within(bar).getByRole("button", { name: "Reject 2" }));

    const dialog = await screen.findByRole("dialog");
    expect(dialog).toHaveTextContent(
      "These 2 requests will be rejected with the same reason",
    );
    fireEvent.change(within(dialog).getByRole("textbox"), {
      target: { value: "Year-end freeze" },
    });
    fireEvent.click(within(dialog).getByRole("button", { name: "Reject" }));

    await waitFor(() => expect(batches).toHaveLength(1));
    expect(batches[0]).toEqual({
      actions: [
        {
          type: "leave",
          public_id: "L1",
          action: "reject",
          reason: "Year-end freeze",
        },
        {
          type: "leave",
          public_id: "L2",
          action: "reject",
          reason: "Year-end freeze",
        },
      ],
    });
  });

  it("does not send a ticked request the filter now hides", async () => {
    renderAt();
    await screen.findByText("Hana Girma");

    fireEvent.click(
      screen.getByRole("checkbox", {
        name: "Select the request from Hana Girma",
      }),
    );
    fireEvent.click(
      screen.getByRole("checkbox", {
        name: "Select the request from Abebe Kebede",
      }),
    );
    // The address changes without the select (a link, Back): the hidden
    // request stays ticked but only what is on screen is sent.
    window.history.replaceState(null, "", "/approvals?type=leave");
    window.dispatchEvent(new PopStateEvent("popstate"));

    await waitFor(() =>
      expect(screen.queryByText("Hana Girma")).not.toBeInTheDocument(),
    );
    const bar = screen.getByRole("region", { name: "Selected requests" });
    expect(within(bar).getByText("1 selected")).toBeInTheDocument();
    fireEvent.click(within(bar).getByRole("button", { name: "Approve 1" }));

    await waitFor(() => expect(batches).toHaveLength(1));
    expect(batches[0]).toEqual({
      actions: [{ type: "leave", public_id: "L1", action: "approve" }],
    });
  });
});
