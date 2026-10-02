import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import KioskSessionsPage from "@/app/(dashboard)/attendance/kiosks/page";
import QrGeneratorPage from "@/app/(dashboard)/attendance/qr/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const page = (rows: unknown[], current = 1, last = 1) => ({
  data: rows,
  meta: { current_page: current, last_page: last, per_page: 25, total: 26 },
  links: {},
});

/** Shaped like KioskSessionResource (no token outside creation). */
function kiosk(id: string, name: string) {
  return {
    public_id: id,
    name,
    branch: { public_id: "B1", name: "Head Office" },
    device_identifier: null,
    status: "active",
    last_activity_at: null,
    activated_at: "2026-09-01T06:00:00+00:00",
    deactivated_at: null,
    created_at: "2026-09-01T06:00:00+00:00",
  };
}

function me(role: string, permissions: string[]) {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Test User", role },
      tenant: { public_id: "T1", name: "Demo", timezone: "Africa/Addis_Ababa" },
      permissions,
    }),
  );
}

const branches = http.get("*/api/v1/organization/branches", () =>
  HttpResponse.json(page([{ public_id: "B1", name: "Head Office" }])),
);

function renderWithQuery(ui: React.ReactNode) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>,
  );
}

describe("<KioskSessionsPage>", () => {
  it("reaches kiosks past the first page", async () => {
    // KioskSessionController::index pages by 25 and the page read only the
    // first, so a 26th kiosk could be neither seen nor deactivated.
    server.use(
      me("hr_admin", ["attendance.manage"]),
      branches,
      http.get("*/api/v1/kiosk-sessions", ({ request }) => {
        const p = Number(new URL(request.url).searchParams.get("page") ?? 1);
        return HttpResponse.json(
          p === 2
            ? page([kiosk("K26", "Warehouse Gate")], 2, 2)
            : page([kiosk("K1", "Front Desk")], 1, 2),
        );
      }),
    );
    const user = userEvent.setup();
    renderWithQuery(<KioskSessionsPage />);

    expect(await screen.findByText("Front Desk")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Next" }));
    expect(await screen.findByText("Warehouse Gate")).toBeInTheDocument();
  });

  it("admits a custom role holding attendance.manage", async () => {
    // Every kiosk-session endpoint checks attendance.manage; the page was
    // gated on the HR-admin role tier instead.
    server.use(
      me("custom", ["attendance.manage"]),
      branches,
      http.get("*/api/v1/kiosk-sessions", () =>
        HttpResponse.json(page([kiosk("K1", "Front Desk")])),
      ),
    );
    renderWithQuery(<KioskSessionsPage />);

    expect(await screen.findByText("Front Desk")).toBeInTheDocument();
  });
});

describe("<QrGeneratorPage>", () => {
  it("offers every shift, not just the first page", async () => {
    // The shift picker took one page of /shifts (25 rows) as the whole list.
    server.use(
      me("hr_admin", ["attendance.manage"]),
      branches,
      http.get("*/api/v1/shifts", ({ request }) => {
        const p = Number(new URL(request.url).searchParams.get("page") ?? 1);
        return HttpResponse.json(
          p === 2
            ? page([{ public_id: "S26", name: "Night Shift" }], 2, 2)
            : page([{ public_id: "S1", name: "Day Shift" }], 1, 2),
        );
      }),
    );
    const user = userEvent.setup();
    renderWithQuery(<QrGeneratorPage />);

    await user.click(await screen.findByRole("combobox", { name: /Shift/ }));
    expect(
      await screen.findByRole("option", { name: "Night Shift" }),
    ).toBeInTheDocument();
  });
});
