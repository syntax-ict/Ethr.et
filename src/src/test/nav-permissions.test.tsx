import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import type { ReactNode } from "react";
import { server } from "./msw/server";
import { SidebarNav } from "@/components/layouts/sidebar-nav";
import { SettingsNav } from "@/components/layouts/settings-nav";

vi.mock("next/navigation", () => ({
  usePathname: () => "/attendance/overtime",
}));

/**
 * A custom role: the permissions it was given, at the lowest role level. The
 * real usePermissions runs, so each link is checked against the ability map.
 */
function asCustomRole(permissions: string[]) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Custom Role", role: "employee" },
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
        permissions,
      }),
    ),
  );
}

function renderWith(node: ReactNode) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{node}</QueryClientProvider>,
  );
}

// Every one of these links was gated on `employee.create` while its screen's
// API checks something else, so under a custom role a link led to a 403 or a
// permitted screen had no link (audit N78).
describe("navigation follows the ability each screen's API checks", () => {
  it("offers attendance and device screens to a role holding exactly those", async () => {
    asCustomRole(["attendance.viewAll", "device.viewAny"]);
    renderWith(<SidebarNav />);

    expect(
      await screen.findByRole("link", { name: /^overtime$/i }),
    ).toHaveAttribute("href", "/attendance/overtime");
    expect(screen.getByRole("link", { name: /^devices$/i })).toHaveAttribute(
      "href",
      "/devices",
    );
    // shift.create was not granted; the shifts screen manages them.
    expect(
      screen.queryByRole("link", { name: /shifts & schedules/i }),
    ).not.toBeInTheDocument();
  });

  it("does not offer those screens to a role that can only create employees", async () => {
    // users.viewAny gives the nav one link to wait on, so the absences below
    // are asserted after the permissions load rather than before.
    asCustomRole(["employee.create", "employee.viewAny", "users.viewAny"]);
    renderWith(<SettingsNav />);

    await screen.findByRole("link", { name: /users & access/i });
    for (const name of [
      /attendance rules/i,
      /shift rules/i,
      /leave types/i,
      /^holidays$/i,
    ]) {
      expect(screen.queryByRole("link", { name })).not.toBeInTheDocument();
    }
  });

  it("offers each settings screen for the ability its API checks", async () => {
    asCustomRole([
      "attendance.manage",
      "shift.create",
      "leave.manageTypes",
      "holiday.create",
    ]);
    renderWith(<SettingsNav />);

    for (const [name, href] of [
      [/attendance rules/i, "/attendance/settings"],
      [/shift rules/i, "/settings/shifts"],
      [/leave types/i, "/settings/leave-types"],
      [/^holidays$/i, "/settings/holidays"],
    ] as const) {
      expect(await screen.findByRole("link", { name })).toHaveAttribute(
        "href",
        href,
      );
    }
  });
});
