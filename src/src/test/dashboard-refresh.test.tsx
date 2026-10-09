import { describe, expect, it } from "vitest";
import { act, renderHook } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import type { ReactNode } from "react";
import { server } from "./msw/server";
import { useApproveLeave, useRejectLeave } from "@/features/leave/api";
import {
  useApproveCorrection,
  useRejectCorrection,
} from "@/features/attendance/api";
import { useBatchApprovals } from "@/features/approvals/api";

// The Approvals badge reads the manager dashboard. No decision invalidated it,
// so the badge kept counting requests already decided until a reload (audit
// N80).
describe("deciding a request refreshes the dashboard's Approvals badge", () => {
  const decisions: [
    string,
    () => { mutateAsync: (v: never) => unknown },
    unknown,
  ][] = [
    ["approving leave", useApproveLeave, "L1"],
    ["rejecting leave", useRejectLeave, { publicId: "L1", reason: "No" }],
    ["approving a correction", useApproveCorrection, "C1"],
    [
      "rejecting a correction",
      useRejectCorrection,
      { publicId: "C1", reason: "No" },
    ],
    ["a batch decision", useBatchApprovals, { items: [], action: "approve" }],
  ];

  it.each(decisions)("%s", async (_label, useDecision, vars) => {
    server.use(
      http.put("*/api/v1/leave/:id/:action", () => HttpResponse.json({})),
      http.put("*/api/v1/attendance/corrections/:id/:action", () =>
        HttpResponse.json({}),
      ),
      http.post("*/api/v1/approvals/batch", () =>
        HttpResponse.json({ succeeded: [], failed: [] }),
      ),
    );
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    client.setQueryData(["dashboard", "manager"], { pending_approvals: {} });
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );

    const { result } = renderHook(() => useDecision(), { wrapper });
    await act(async () => {
      await result.current.mutateAsync(vars as never);
    });

    expect(client.getQueryState(["dashboard", "manager"])?.isInvalidated).toBe(
      true,
    );
  });
});
