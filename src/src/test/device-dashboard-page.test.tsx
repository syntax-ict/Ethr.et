import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
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

const emptyList = () =>
  http.get("*/api/v1/devices", () =>
    HttpResponse.json({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
      links: { next: null, prev: null },
    }),
  );

const deviceRow = (public_id: string, receives_only: boolean) => ({
  public_id,
  name: `Terminal ${public_id}`,
  adapter_type: "hikvision",
  status: "online",
  receives_only,
  serial_number: null,
  auto_sync: false,
  last_sync_at: null,
  branch: null,
  branch_public_id: null,
  attendance_records_count: 0,
});

function serveDevices(rows: object[]) {
  server.use(
    http.get("*/api/v1/devices", () =>
      HttpResponse.json({
        data: rows,
        meta: {
          current_page: 1,
          last_page: 1,
          per_page: 25,
          total: rows.length,
        },
        links: { next: null, prev: null },
      }),
    ),
  );
}

const stats = {
  total: 1,
  online: 0,
  offline: 0,
  error: 0,
  quiet: 0,
  pending: 1,
  auto_sync_enabled: 0,
  events_today: 0,
  punches_today: { recorded: 0, held: 0, waiting: 0 },
  unmatched: { badges: 0, punches: 0 },
  quarantined: { punches: 0, devices: [] },
  last_sync_at: null,
  sync_stats_24h: { success: 0, partial: 0, failed: 0, offline: 0 },
};

function serveStats(overrides: object = {}) {
  server.use(
    http.get("*/api/v1/devices/dashboard", () =>
      HttpResponse.json({ ...stats, ...overrides }),
    ),
  );
}

describe("<DeviceDashboardPage> cards", () => {
  it("shows a device that is not connected yet in its own card", async () => {
    serveStats();
    server.use(emptyList());
    renderPage();

    const label = await screen.findByText("Not connected yet");
    const card = label.closest('[class*="rounded-xl"]') as HTMLElement;
    expect(card).toHaveTextContent("Not connected yet");
    expect(card).toHaveTextContent("1");
    expect(screen.queryByText(/last sync across/i)).not.toBeInTheDocument();
    expect(screen.queryByText("Events Today")).not.toBeInTheDocument();
  });

  it("counts a quiet terminal in its own card, not under Online", async () => {
    serveStats({ total: 2, online: 1, quiet: 1, pending: 0 });
    server.use(emptyList());
    renderPage();

    const label = await screen.findByText("Quiet");
    const card = label.closest('[class*="rounded-xl"]') as HTMLElement;
    expect(card).toHaveTextContent("1");
  });

  it("shows the three punch figures and where the last contact was", async () => {
    serveStats({
      punches_today: { recorded: 12, held: 3400, waiting: 7 },
      last_sync_at: new Date(Date.now() - 5 * 60 * 1000).toISOString(),
    });
    server.use(emptyList());
    renderPage();

    const title = await screen.findByText("Punches today");
    const card = title.closest('[class*="rounded-xl"]') as HTMLElement;
    expect(within(card).getByText("Recorded as attendance")).toBeVisible();
    expect(within(card).getByText("12")).toBeVisible();
    expect(within(card).getByText("Held, badge not recognised")).toBeVisible();
    expect(within(card).getByText("3,400")).toHaveClass("text-warning");
    expect(within(card).getByText("Waiting to be worked")).toBeVisible();
    expect(within(card).getByText("7")).toBeVisible();
    expect(card).toHaveTextContent(/Last heard from a device:/);
  });
});

describe("<DeviceDashboardPage> punches that need attention", () => {
  it("says how many wait and links to the device that holds them", async () => {
    serveStats({
      quarantined: {
        punches: 3,
        devices: [{ device_public_id: "DEV9", name: "Main gate", punches: 3 }],
      },
    });
    server.use(emptyList());
    renderPage();

    const banner = await screen.findByRole("status");
    expect(banner).toHaveTextContent("Punches that need attention");
    expect(
      within(banner).getByRole("link", { name: "Main gate, 3 punches" }),
    ).toHaveAttribute("href", "/devices/DEV9#quarantine");
  });
});

describe("<DeviceDashboardPage> one punch that needs attention", () => {
  it("says 1 punch, not 1 punches", async () => {
    serveStats({
      quarantined: {
        punches: 1,
        devices: [{ device_public_id: "DEV9", name: "Main gate", punches: 1 }],
      },
    });
    server.use(emptyList());
    renderPage();

    const banner = await screen.findByRole("status");
    expect(banner).toHaveTextContent("1 punch could not be recorded");
    expect(
      within(banner).getByRole("link", { name: "Main gate, 1 punch" }),
    ).toBeInTheDocument();
  });
});

describe("<DeviceDashboardPage> unrecognised badges", () => {
  it("says how many badges and punches wait, and links to them", async () => {
    serveStats({ unmatched: { badges: 80, punches: 12345 } });
    server.use(emptyList());
    renderPage();

    const banner = await screen.findByRole("status");
    expect(banner).toHaveTextContent("Badges not recognised");
    expect(banner).toHaveTextContent(
      "80 badges are not matched to anyone, and 12,345 punches are held",
    );
    expect(
      within(banner).getByRole("link", { name: "Review badges" }),
    ).toHaveAttribute("href", "/devices#unmatched");
  });

  it("is absent when no badge is waiting", async () => {
    serveStats();
    server.use(emptyList());
    renderPage();

    await screen.findByText("Punches today");
    expect(screen.queryByText("Badges not recognised")).not.toBeInTheDocument();
  });
});

describe("<DeviceDashboardPage> sync overview", () => {
  it("is left out when every device only sends", async () => {
    serveStats();
    serveDevices([deviceRow("D1", true), deviceRow("D2", true)]);
    renderPage();

    await screen.findByText("Terminal D1");
    expect(screen.queryByText(/sync overview/i)).not.toBeInTheDocument();
  });

  it("is shown when at least one device is called by ETHR", async () => {
    serveStats();
    serveDevices([deviceRow("D1", true), deviceRow("D2", false)]);
    renderPage();

    await screen.findByText("Terminal D1");
    expect(screen.getByText(/sync overview/i)).toBeInTheDocument();
  });
});
