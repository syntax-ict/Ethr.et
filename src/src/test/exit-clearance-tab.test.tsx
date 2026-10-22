import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { ExitClearanceTab } from "@/features/offboarding/components/exit-clearance-tab";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const EMPLOYEE = "01HZEMP000000000000000001";

function item(
  publicId: string,
  label: string,
  extra: Record<string, unknown> = {},
) {
  return {
    public_id: publicId,
    key: null,
    label,
    owner: "hr",
    kind: "manual",
    done: false,
    done_at: null,
    done_by: null,
    note: null,
    can_tick: true,
    ...extra,
  };
}

/** Shaped like the `exitClearance.show` response. */
function clearance(owedCents: number) {
  return {
    clearance: {
      public_id: "01HZCLR000000000000000001",
      exit_status: "resigned",
      leaving_date: "2026-11-01",
      status: "open",
      completed_at: null,
      created_at: "2026-10-22T08:00:00+00:00",
      done_count: 0,
      item_count: 2,
      items: [
        item("01HZITEM00000000000000001", "ID and access card returned"),
        item("01HZITEM00000000000000002", "Loans and advances settled", {
          owner: "finance",
          kind: "obligations",
        }),
      ],
    },
    obligations: {
      loans_cents: owedCents,
      cost_sharing_cents: 0,
      total_cents: owedCents,
    },
  };
}

function setup(
  body: Record<string, unknown>,
  permissions: string[] = ["employee.transition"],
) {
  const puts: unknown[] = [];
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "HR", role: "hr_admin" },
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
        permissions,
      }),
    ),
    http.get(`*/api/v1/employees/${EMPLOYEE}/exit-clearance`, () =>
      HttpResponse.json(body),
    ),
    http.put(
      `*/api/v1/employees/${EMPLOYEE}/exit-clearance/items/:item`,
      async ({ request, params }) => {
        puts.push({ item: params.item, body: await request.json() });
        return HttpResponse.json(item(String(params.item), "x"));
      },
    ),
  );

  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  render(<ExitClearanceTab employeeId={EMPLOYEE} />, { wrapper: Wrapper });
  return puts;
}

describe("ExitClearanceTab", () => {
  it("offers HR a way to start one when the leaver has none", async () => {
    setup({ clearance: null, obligations: clearance(0).obligations });

    expect(await screen.findByText("No exit clearance yet")).toBeVisible();
    expect(
      await screen.findByRole("button", { name: "Start exit clearance" }),
    ).toBeVisible();
  });

  it("ticks a line straight away", async () => {
    const puts = setup(clearance(0));
    const user = userEvent.setup();

    await user.click(
      await screen.findByRole("checkbox", {
        name: "ID and access card returned",
      }),
    );

    await waitFor(() =>
      expect(puts).toEqual([
        {
          item: "01HZITEM00000000000000001",
          body: { done: true, note: null },
        },
      ]),
    );
  });

  it("shows what is owed and asks for a note before ticking the balance line", async () => {
    const puts = setup(clearance(320_000));
    const user = userEvent.setup();

    expect(await screen.findByText(/Still owed/)).toBeVisible();
    expect(screen.getByText("3,200.00 ETB")).toBeVisible();

    await user.click(
      screen.getByRole("checkbox", { name: "Loans and advances settled" }),
    );
    const mark = await screen.findByRole("button", { name: "Mark done" });
    expect(mark).toBeDisabled();
    expect(puts).toEqual([]);

    await user.type(
      screen.getByLabelText("Note"),
      "Deducted from the final pay",
    );
    await user.click(mark);

    await waitFor(() =>
      expect(puts).toEqual([
        {
          item: "01HZITEM00000000000000002",
          body: { done: true, note: "Deducted from the final pay" },
        },
      ]),
    );
  });

  it("leaves a line the reader may not tick disabled", async () => {
    const body = clearance(0);
    body.clearance.items[0] = {
      ...body.clearance.items[0],
      can_tick: false,
    };
    setup(body, []);

    expect(
      await screen.findByRole("checkbox", {
        name: "ID and access card returned",
      }),
    ).toBeDisabled();
  });
});
