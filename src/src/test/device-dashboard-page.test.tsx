import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import DeviceDashboardPage from "@/app/(dashboard)/devices/dashboard/page";

/**
 * A terminal that is not connected yet is in the total, so it must be in a card
 * too: the card named Not connected yet carries it (2026-11-15).
 */
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: () => true,
    can: { viewDevices: true },
    role: "hr_admin",
  }),
}));

vi.mock("@/features/auth/api", async (importOriginal) => ({
  ...(await importOriginal<object>()),
  useCurrentTenant: () => ({ data: { timezone: "Africa/Addis_Ababa" } }),
}));

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <DeviceDashboardPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem("locale", "en");
});

describe("<DeviceDashboardPage> cards", () => {
  it("shows a device that is not connected yet in its own card", async () => {
    server.use(
      http.get("*/api/v1/devices/dashboard", () =>
        HttpResponse.json({
          total: 1,
          online: 0,
          offline: 0,
          error: 0,
          pending: 1,
          auto_sync_enabled: 0,
          events_today: 0,
          last_sync_at: null,
          sync_stats_24h: { success: 0, partial: 0, failed: 0, offline: 0 },
        }),
      ),
      http.get("*/api/v1/devices", () =>
        HttpResponse.json({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
          links: { next: null, prev: null },
        }),
      ),
    );
    renderPage();

    const label = await screen.findByText("Not connected yet");
    const card = label.closest('[class*="rounded-xl"]') as HTMLElement;
    expect(card).toHaveTextContent("Not connected yet");
    expect(card).toHaveTextContent("1");
    expect(screen.queryByText(/last sync across/i)).not.toBeInTheDocument();
  });
});
