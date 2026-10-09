import { describe, expect, it, vi } from "vitest";
import { render, renderHook, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { usePlanFeatures } from "@/features/auth/api";
import ReportsPage from "@/app/(dashboard)/reports/page";

vi.mock("next/navigation", () => ({
  usePathname: () => "/reports",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    role: "hr_admin",
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: () => true,
    can: new Proxy({} as Record<string, boolean>, { get: () => true }),
  }),
}));

function serveMe(planFeatures: string[] | null) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", role: "hr_admin" },
        permissions: [],
        plan_features: planFeatures,
        tenant: null,
      }),
    ),
  );
}

function client() {
  return new QueryClient({ defaultOptions: { queries: { retry: false } } });
}

// The UI had no idea of the plan: a Starter tenant was offered Payroll,
// Reports, Webhooks and the Audit Log, and refused on every action (N66).
describe("plan features on screen", () => {
  it("allows everything when the plan restricts nothing", async () => {
    serveMe(null);
    const qc = client();
    const { result } = renderHook(() => usePlanFeatures(), {
      wrapper: ({ children }) => (
        <QueryClientProvider client={qc}>{children}</QueryClientProvider>
      ),
    });

    await waitFor(() => expect(result.current.isLoading).toBe(false));
    expect(result.current.has("payroll")).toBe(true);
    expect(result.current.has("audit_log")).toBe(true);
  });

  it("allows only what the plan lists", async () => {
    serveMe(["attendance", "leave", "employee_management"]);
    const qc = client();
    const { result } = renderHook(() => usePlanFeatures(), {
      wrapper: ({ children }) => (
        <QueryClientProvider client={qc}>{children}</QueryClientProvider>
      ),
    });

    await waitFor(() => expect(result.current.isLoading).toBe(false));
    expect(result.current.has("attendance")).toBe(true);
    expect(result.current.has("reports")).toBe(false);
  });

  it("explains on the reports page, instead of offering a builder that is refused", async () => {
    serveMe(["attendance", "leave", "employee_management"]);
    render(
      <QueryClientProvider client={client()}>
        <ReportsPage />
      </QueryClientProvider>,
    );

    expect(
      await screen.findByText(/not included in your organisation's plan/i),
    ).toBeInTheDocument();
    expect(screen.queryByRole("tab")).not.toBeInTheDocument();
  });
});
