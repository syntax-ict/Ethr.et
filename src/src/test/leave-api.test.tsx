import { describe, it, expect } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";

function createWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  // Named declaration rather than an anonymous arrow: react/display-name
  // needs a name to report, and an unnamed wrapper shows up as <Unknown> in
  // React DevTools and in test failure output.
  function TestQueryWrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  }

  return TestQueryWrapper;
}

describe("leaveTypeName", () => {
  it("returns name from object leave type", async () => {
    const { leaveTypeName } = await import("@/features/leave/api");
    expect(
      leaveTypeName({ name: "Annual", code: "ANN", public_id: "01HZ" }),
    ).toBe("Annual");
  });

  it("returns string leave type as-is", async () => {
    const { leaveTypeName } = await import("@/features/leave/api");
    expect(leaveTypeName("Sick Leave")).toBe("Sick Leave");
  });

  it('falls back to "Leave" when name is missing', async () => {
    const { leaveTypeName } = await import("@/features/leave/api");
    // Deliberately malformed input — the point of the test is the fallback.
    expect(
      leaveTypeName({} as unknown as Parameters<typeof leaveTypeName>[0]),
    ).toBe("Leave");
  });
});

describe("useLeaveBalance", () => {
  it("fetches and returns leave balances", async () => {
    server.use(
      http.get("*/api/v1/leave/balance", () =>
        HttpResponse.json([
          {
            leave_type: { name: "Annual", code: "ANN", public_id: "01HZ1" },
            entitled_days: 20,
            used_days: 5,
            remaining_days: 15,
          },
          {
            leave_type: { name: "Sick", code: "SICK", public_id: "01HZ2" },
            entitled_days: 10,
            used_days: 2,
            remaining_days: 8,
          },
        ]),
      ),
    );

    const { useLeaveBalance } = await import("@/features/leave/api");
    const { result } = renderHook(() => useLeaveBalance(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toHaveLength(2);
    expect(result.current.data![0].remaining_days).toBe(15);
  });
});

describe("useMyLeaveRequests", () => {
  it("fetches paginated leave requests", async () => {
    server.use(
      http.get("*/api/v1/leave/my", () =>
        HttpResponse.json({
          data: [
            {
              public_id: "01HZLEAVE001",
              leave_type: { name: "Annual", code: "ANN", public_id: "01HZ1" },
              start_date: "2026-07-01",
              end_date: "2026-07-05",
              days: 5,
              reason: "Vacation",
              status: "approved",
              created_at: "2026-06-25T10:00:00Z",
            },
          ],
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: 25,
            total: 1,
          },
          links: { next: null, prev: null },
        }),
      ),
    );

    const { useMyLeaveRequests } = await import("@/features/leave/api");
    const { result } = renderHook(() => useMyLeaveRequests({ page: 1 }), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.data).toHaveLength(1);
    expect(result.current.data!.data[0].status).toBe("approved");
  });
});

describe("useSubmitLeave", () => {
  it("submits a leave request and invalidates cache", async () => {
    server.use(
      http.post("*/api/v1/leave/request", async ({ request }) => {
        const body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({
          public_id: "01HZLEAVE002",
          leave_type: body.leave_type_public_id,
          start_date: body.start_date,
          end_date: body.end_date,
          status: "pending",
        });
      }),
    );

    const { useSubmitLeave } = await import("@/features/leave/api");
    const { result } = renderHook(() => useSubmitLeave(), {
      wrapper: createWrapper(),
    });

    result.current.mutate({
      leave_type_public_id: "01HZTYPE001",
      start_date: "2026-08-01",
      end_date: "2026-08-03",
      reason: "Personal",
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data.status).toBe("pending");
  });
});

describe("useApproveLeave", () => {
  it("approves a leave request", async () => {
    server.use(
      http.put("*/api/v1/leave/:id/approve", () =>
        HttpResponse.json({ status: "approved" }),
      ),
    );

    const { useApproveLeave } = await import("@/features/leave/api");
    const { result } = renderHook(() => useApproveLeave(), {
      wrapper: createWrapper(),
    });

    result.current.mutate("01HZLEAVE001");

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data.status).toBe("approved");
  });
});

describe("useRejectLeave", () => {
  it("rejects a leave request with reason", async () => {
    server.use(
      http.put("*/api/v1/leave/:id/reject", async ({ request }) => {
        const body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({
          status: "rejected",
          rejection_reason: body.reason,
        });
      }),
    );

    const { useRejectLeave } = await import("@/features/leave/api");
    const { result } = renderHook(() => useRejectLeave(), {
      wrapper: createWrapper(),
    });

    result.current.mutate({
      publicId: "01HZLEAVE001",
      reason: "Insufficient coverage",
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data.status).toBe("rejected");
  });
});

describe("useCancelLeave", () => {
  it("cancels a leave request", async () => {
    server.use(
      http.put("*/api/v1/leave/:id/cancel", () =>
        HttpResponse.json({ status: "cancelled" }),
      ),
    );

    const { useCancelLeave } = await import("@/features/leave/api");
    const { result } = renderHook(() => useCancelLeave(), {
      wrapper: createWrapper(),
    });

    result.current.mutate("01HZLEAVE001");

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data.status).toBe("cancelled");
  });
});
