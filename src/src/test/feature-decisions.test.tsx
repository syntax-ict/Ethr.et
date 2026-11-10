import { describe, expect, it } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { useHiddenFeatures, usePlanFeatures } from "@/features/auth/api";

/**
 * The screen reads each feature's decision from `/me` `features`, which
 * weighs the platform switch, any grant or revoke and the plan, and falls
 * back to `plan_features` only for a server that does not send it
 * (docs/FEATURE-CONTROL-CENTER-DESIGN.md §9, PR 4).
 */
function serveMe(body: Record<string, unknown>) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "u1", name: "Admin", role: "hr_admin" },
        permissions: [],
        tenant: null,
        ...body,
      }),
    ),
  );
}

function wrapper({ children }: { children: React.ReactNode }) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

describe("feature decisions on the screen", () => {
  it("follows the decision, so a grant beats a plan that leaves the feature out", async () => {
    serveMe({
      plan_features: ["employee_management"],
      features: {
        payroll: { on: true, visible: true, reason: "granted" },
        reports: { on: false, visible: false, reason: "platform_disabled" },
      },
    });
    const { result } = renderHook(() => usePlanFeatures(), { wrapper });

    await waitFor(() => expect(result.current.isLoading).toBe(false));
    expect(result.current.has("payroll")).toBe(true);
    expect(result.current.has("reports")).toBe(false);
  });

  it("reads the plan list from a server that sends no decisions", async () => {
    serveMe({ plan_features: ["payroll"] });
    const { result } = renderHook(() => usePlanFeatures(), { wrapper });

    await waitFor(() => expect(result.current.isLoading).toBe(false));
    expect(result.current.has("payroll")).toBe(true);
    expect(result.current.has("reports")).toBe(false);
  });

  it("names what is on but Hidden, and nothing that is off", async () => {
    serveMe({
      features: {
        payroll: { on: true, visible: false, reason: "enabled" },
        leave: { on: true, visible: true, reason: "enabled" },
        reports: { on: false, visible: false, reason: "plan" },
      },
    });
    const { result } = renderHook(() => useHiddenFeatures(), { wrapper });

    await waitFor(() => expect(result.current.size).toBe(1));
    expect([...result.current]).toEqual(["payroll"]);
  });
});
