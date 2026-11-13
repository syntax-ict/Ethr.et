import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import DevicesPage from "@/app/(dashboard)/devices/page";

/**
 * A terminal that only sends is never called, so its card does not offer Test,
 * Pull, enrolled users or history, and says what ETHR last heard from it. A
 * terminal ETHR calls keeps all of them (2026-11-12).
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

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const base = {
  location_description: null,
  serial_number: null,
  auto_sync: true,
  sync_interval_minutes: 5,
  last_sync_at: null,
  branch: null,
  branch_public_id: null,
  attendance_records_count: 0,
  sync_logs_count: 0,
  created_at: "2026-11-12T06:00:00Z",
  updated_at: "2026-11-12T06:00:00Z",
};

function serve(devices: object[]) {
  server.use(
    http.get("*/api/v1/devices", () =>
      HttpResponse.json({
        data: devices,
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 2 },
        links: { next: null, prev: null },
      }),
    ),
    http.get("*/api/v1/branches", () =>
      HttpResponse.json({
        data: [],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
        links: { next: null, prev: null },
      }),
    ),
  );
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <DevicesPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem("locale", "en");
});

describe("<DevicesPage> cards", () => {
  it("shows a terminal that only sends as not connected yet, with no actions it cannot perform", async () => {
    serve([
      {
        ...base,
        public_id: "PUSH1",
        name: "Habru front door",
        adapter_type: "hikvision",
        receives_only: true,
        status: "pending",
        auto_sync: false,
      },
      {
        ...base,
        public_id: "PULL1",
        name: "Main gate",
        adapter_type: "hikvision",
        receives_only: false,
        status: "online",
      },
    ]);
    renderPage();

    const push = (await screen.findByText("Habru front door")).closest(
      '[class*="p-4"]',
    ) as HTMLElement;
    const pull = screen
      .getByText("Main gate")
      .closest('[class*="p-4"]') as HTMLElement;

    expect(within(push).getByText("Not connected yet")).toBeInTheDocument();
    expect(
      within(push).getByText("Nothing received from this terminal yet."),
    ).toBeInTheDocument();
    for (const name of [/^test$/i, /^pull$/i]) {
      expect(
        within(push).queryByRole("button", { name }),
      ).not.toBeInTheDocument();
    }

    expect(within(pull).getByText("Online")).toBeInTheDocument();
    expect(
      within(pull).getByRole("button", { name: /^test$/i }),
    ).toBeInTheDocument();
    expect(
      within(pull).getByRole("button", { name: /^pull$/i }),
    ).toBeInTheDocument();
  });
});
