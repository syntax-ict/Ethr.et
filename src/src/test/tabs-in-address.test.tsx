import { afterEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";

vi.mock("next/navigation", () => ({
  usePathname: () => "/",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    role: "tenant_admin",
    hasPermission: () => true,
    isAtLeast: () => true,
    hasRole: () => true,
    can: new Proxy({} as Record<string, boolean>, { get: () => true }),
  }),
}));

vi.mock("@/features/auth/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/features/auth/api")>()),
  usePlanFeatures: () => ({ has: () => true }),
}));

import ReportsPage from "@/app/(dashboard)/reports/page";
import AnalyticsPage from "@/app/(dashboard)/analytics/page";

function renderAt(path: string, page: ReactNode) {
  window.history.replaceState(null, "", path);
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{page}</QueryClientProvider>,
  );
}

afterEach(() => {
  window.history.replaceState(null, "", "/");
});

// A reload or a shared link landed on the first tab whatever was open
// (FRONTEND_REDESIGN_PLAN.md, backlog 21).
describe("tabs kept in the address", () => {
  it("opens the reports tab named in ?tab=", async () => {
    renderAt("/reports?tab=scheduled", <ReportsPage />);

    const tab = await screen.findByRole("tab", { selected: true });
    expect(tab).toHaveAttribute("data-state", "active");
    expect(tab.getAttribute("aria-controls")).toMatch(/scheduled/);
  });

  it("opens the analytics tab named in ?tab=", async () => {
    renderAt("/analytics?tab=payroll", <AnalyticsPage />);

    const tab = await screen.findByRole("tab", { selected: true });
    expect(tab.getAttribute("aria-controls")).toMatch(/payroll/);
  });
});
