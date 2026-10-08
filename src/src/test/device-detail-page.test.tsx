import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { DeviceDetail } from "@/app/(dashboard)/devices/[id]/device-detail";

// The page is wrapped in <RoleGate minRole="hr_admin">; grant access directly.
// `granted` decides hasPermission: everything by default, or a seeded role's set.
const granted = vi.hoisted(() => ({ abilities: null as string[] | null }));
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: (ability: string) =>
      granted.abilities === null || granted.abilities.includes(ability),
    can: {},
    role: "hr_admin",
  }),
}));

/**
 * The device detail page reads two different pagination shapes, and both are
 * pinned here with responses shaped like the real ones. Sync logs are a
 * resource collection (`meta.last_page`); events are a bare Laravel paginator
 * (`last_page` at the top level, no `meta`). The page used to read
 * `events.meta.last_page`, so the Events tab never offered a second page —
 * and the first typed version of the hooks crashed on it in the browser.
 */
const DEVICE = {
  public_id: "DEV1",
  name: "Gate Simulator",
  location_description: null,
  serial_number: null,
  adapter_type: "mock",
  status: "online",
  auto_sync: true,
  sync_interval_minutes: 5,
  last_sync_at: null,
  branch: null,
  branch_public_id: null,
  attendance_records_count: 20,
  sync_logs_count: 0,
  created_at: "2026-10-01T10:00:00Z",
  updated_at: "2026-10-01T10:00:00Z",
};

const event = (n: number) => ({
  public_id: `EV${n}`,
  employee_name: `Employee ${n}`,
  employee_code: `E${n}`,
  date: "2026-10-01",
  check_in: "2026-10-01T08:00:00+03:00",
  check_out: null,
  source: "biometric",
  status: "present",
  confidence_score: 100,
  created_at: "2026-10-01T05:00:00Z",
});

function renderDetail() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<DeviceDetail routeId="DEV1" />, { wrapper: Wrapper });
}

describe("<DeviceDetail>", () => {
  it("pages through attendance events, whose paginator has no meta block", async () => {
    server.use(
      http.get("*/api/v1/devices/DEV1", () => HttpResponse.json(DEVICE)),
      http.get("*/api/v1/devices/DEV1/sync-logs", () =>
        HttpResponse.json({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 },
          links: {},
        }),
      ),
      http.get("*/api/v1/devices/DEV1/events", ({ request }) => {
        const page = Number(new URL(request.url).searchParams.get("page"));
        return HttpResponse.json({
          current_page: page,
          data: [event(page)],
          last_page: 2,
          per_page: 15,
          total: 16,
          from: page,
          to: page,
          path: "/api/v1/devices/DEV1/events",
          first_page_url: null,
          last_page_url: null,
          next_page_url: null,
          prev_page_url: null,
          links: [],
        });
      }),
    );
    const user = userEvent.setup();
    renderDetail();

    expect(await screen.findByText("Gate Simulator")).toBeInTheDocument();

    await user.click(screen.getByRole("tab", { name: "Attendance Events" }));
    const panel = await screen.findByRole("tabpanel");
    expect(await within(panel).findByText("Employee 1")).toBeInTheDocument();

    await user.click(within(panel).getByRole("button", { name: "Next page" }));
    expect(await within(panel).findByText("Employee 2")).toBeInTheDocument();
  });

  it("offers HR only what the seeded HR role may do", async () => {
    // HR is admitted to the page but holds device.viewAny and device.view only.
    // Pull, Edit and Delete were shown and refused with a 403 (N54).
    granted.abilities = ["device.viewAny", "device.view"];
    server.use(
      http.get("*/api/v1/devices/DEV1", () => HttpResponse.json(DEVICE)),
      http.get("*/api/v1/devices/DEV1/sync-logs", () =>
        HttpResponse.json({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 },
          links: {},
        }),
      ),
      http.get("*/api/v1/devices/DEV1/events", () =>
        HttpResponse.json({ data: [], current_page: 1, last_page: 1 }),
      ),
    );

    try {
      renderDetail();

      expect(
        await screen.findByRole("button", { name: /^test$/i }),
      ).toBeInTheDocument();
      for (const name of [/^pull$/i, /^edit$/i, /^delete$/i]) {
        expect(screen.queryByRole("button", { name })).not.toBeInTheDocument();
      }
    } finally {
      granted.abilities = null;
    }
  });
});
