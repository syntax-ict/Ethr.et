import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import AttendanceDevicesPage from "@/app/(dashboard)/attendance/devices/page";
import type { AttendanceDeviceInfo } from "@/features/attendance/api";

/**
 * HR's phones page: a waiting replacement says which phone approving stops,
 * refusing asks why and sends the reason, and the people with no phone yet
 * are one click from the "Still to register" figure.
 */
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({ can: { manageAttendance: true } }),
}));

vi.mock("@/features/auth/api", async (importOriginal) => ({
  ...(await importOriginal<object>()),
  useCurrentTenant: () => ({ data: { timezone: "Africa/Addis_Ababa" } }),
  useCurrentPermissions: () => ({ data: [] }),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const coverage = {
  employees: 12,
  registered: 9,
  pending: 1,
  checked: 11,
  missing: 2,
};

function waiting(): AttendanceDeviceInfo {
  return {
    public_id: "dev-2",
    status: "pending",
    label: "Android, Chrome",
    synced_passkey: false,
    enrolled_inside_geofence: true,
    created_at: "2026-10-28T06:00:00Z",
    last_used_at: null,
    approved_at: null,
    replaces: {
      label: "iPhone, Safari",
      synced_passkey: false,
      created_at: "2026-10-01T06:00:00Z",
      last_used_at: null,
    },
    employee: { public_id: "e1", name: "Abebe Kebede", employee_code: "E-1" },
  };
}

function page(rows: unknown[]) {
  return {
    data: rows,
    meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length },
    links: { next: null, prev: null },
  };
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <AttendanceDevicesPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  localStorage.setItem("locale", "en");
});

describe("AttendanceDevicesPage", () => {
  it("says which phone approving a replacement stops", async () => {
    server.use(
      http.get("*/attendance/devices", () =>
        HttpResponse.json({ ...page([waiting()]), coverage }),
      ),
    );

    renderPage();

    expect(
      await screen.findByText(
        "Replaces iPhone, Safari, not used to punch yet. Approving stops that phone.",
      ),
    ).toBeInTheDocument();
  });

  it("asks why before refusing, and sends the reason", async () => {
    let sent: unknown = null;
    server.use(
      http.get("*/attendance/devices", () =>
        HttpResponse.json({ ...page([waiting()]), coverage }),
      ),
      http.post("*/attendance/devices/dev-2/reject", async ({ request }) => {
        sent = await request.json();
        return HttpResponse.json({ ...waiting(), status: "rejected" });
      }),
    );

    renderPage();
    fireEvent.click(await screen.findByRole("button", { name: "Reject" }));
    const dialog = await screen.findByRole("dialog");
    const confirm = Array.from(dialog.querySelectorAll("button")).find(
      (b) => b.textContent === "Reject",
    )!;
    expect(confirm).toBeDisabled();

    fireEvent.change(dialog.querySelector("textarea")!, {
      target: { value: "Not their own phone" },
    });
    fireEvent.click(confirm);

    await waitFor(() =>
      expect(sent).toEqual({ reason: "Not their own phone" }),
    );
  });

  it("lists the people still to register from the figure", async () => {
    server.use(
      http.get("*/attendance/devices", () =>
        HttpResponse.json({ ...page([]), coverage }),
      ),
      http.get("*/attendance/devices/unregistered", () =>
        HttpResponse.json(
          page([
            {
              public_id: "e9",
              name: "Hana Tesfaye",
              employee_code: "E-9",
              branch: "Bole",
              has_pending_phone: false,
              exempt_until: null,
            },
          ]),
        ),
      ),
    );

    renderPage();
    fireEvent.click(await screen.findByText("Still to register"));

    expect(await screen.findByText("Hana Tesfaye")).toBeInTheDocument();
    expect(
      screen.getByText("Of 11 people whose phone is checked"),
    ).toBeInTheDocument();
  });
});
