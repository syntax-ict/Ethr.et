import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { DeleteDeviceDialog } from "@/features/devices/components/delete-device-dialog";
import { DeviceDetail } from "@/app/(dashboard)/devices/[id]/device-detail";
import DevicesPage from "@/app/(dashboard)/devices/page";

/**
 * One delete confirmation, worded to match DeviceController::destroy(), which
 * soft-deletes and unlinks nothing. The list said "cannot be undone" and named
 * nothing that is kept, and the device page said records would be "unlinked",
 * which is not what happens. There is no restore, so the dialog says to
 * register the terminal again instead.
 */
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: () => true,
    can: { viewDevices: true },
    role: "tenant_admin",
  }),
}));
vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
vi.mock("@/features/auth/api", async (importOriginal) => ({
  ...(await importOriginal<object>()),
  useCurrentTenant: () => ({ data: { timezone: "Africa/Addis_Ababa" } }),
}));

function Wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

/** What the dialog must say, in the words of what the server does. */
function expectsTheTruth() {
  const effects = screen.getByTestId("device-delete-effects");
  expect(effects).toHaveTextContent(/removed from ETHR/i);
  expect(effects).toHaveTextContent(/ETHR stops reading from it/i);
  expect(effects).toHaveTextContent(
    /attendance records are kept and stay linked/i,
  );
  expect(effects).toHaveTextContent(/terminal itself is not wiped/i);
  expect(effects).toHaveTextContent(/register it as a new device/i);
  expect(screen.queryByText(/cannot be undone/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/unlinked/i)).not.toBeInTheDocument();
}

const DEVICE = {
  public_id: "DEV1",
  name: "Gate Simulator",
  location_description: null,
  serial_number: null,
  adapter_type: "mock",
  connection_mode: "pull",
  uses_webhook_token: false,
  status: "online",
  auto_sync: true,
  receives_only: false,
  pulled: true,
  sync_interval_minutes: 5,
  last_sync_at: null,
  branch: null,
  branch_public_id: null,
  attendance_records_count: 20,
  sync_logs_count: 0,
  created_at: "2026-10-01T10:00:00Z",
  updated_at: "2026-10-01T10:00:00Z",
};

describe("<DeleteDeviceDialog>", () => {
  it("names the device, says what happens, and confirms or cancels", async () => {
    const onConfirm = vi.fn();
    const onClose = vi.fn();
    const user = userEvent.setup();
    render(
      <DeleteDeviceDialog
        deviceName="Main gate"
        pending={false}
        onConfirm={onConfirm}
        onClose={onClose}
      />,
    );

    expect(await screen.findByText("Main gate")).toBeInTheDocument();
    expectsTheTruth();

    await user.click(screen.getByRole("button", { name: "Delete" }));
    expect(onConfirm).toHaveBeenCalledTimes(1);
    await user.click(screen.getByRole("button", { name: "Cancel" }));
    expect(onClose).toHaveBeenCalled();
  });

  it("disables Delete while the request is running, and stays closed with no device", () => {
    const { rerender } = render(
      <DeleteDeviceDialog
        deviceName="Main gate"
        pending
        onConfirm={vi.fn()}
        onClose={vi.fn()}
      />,
    );
    expect(screen.getByRole("button", { name: "Delete" })).toBeDisabled();

    rerender(
      <DeleteDeviceDialog
        deviceName={null}
        pending={false}
        onConfirm={vi.fn()}
        onClose={vi.fn()}
      />,
    );
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
  });
});

describe("delete on the two pages", () => {
  it("says the same on the device's own page", async () => {
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
    const user = userEvent.setup();
    render(<DeviceDetail routeId="DEV1" />, { wrapper: Wrapper });

    await user.click(await screen.findByRole("button", { name: "Delete" }));
    expect(await screen.findByText("Delete Device")).toBeInTheDocument();
    expectsTheTruth();
  });

  it("says the same on the list", async () => {
    server.use(
      http.get("*/api/v1/devices", () =>
        HttpResponse.json({
          data: [DEVICE],
          meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
          links: {},
        }),
      ),
      http.get("*/api/v1/devices/unmatched-punches", () =>
        HttpResponse.json({ data: [] }),
      ),
      http.get("*/api/v1/organization/branches", () =>
        HttpResponse.json({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
          links: { next: null, prev: null },
        }),
      ),
    );
    const user = userEvent.setup();
    render(<DevicesPage />, { wrapper: Wrapper });

    await user.click(await screen.findByRole("button", { name: "Actions" }));
    await user.click(await screen.findByRole("menuitem", { name: "Delete" }));
    expect(await screen.findByText("Delete Device")).toBeInTheDocument();
    expectsTheTruth();
  });
});
