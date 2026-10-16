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

// Settings was offered to `settings.manage` alone, so an HR admin, holding
// Users, Leave Types, Holidays, Shift Rules and Attendance Rules, had no way to
// reach any of them (audit N91).
describe("the Settings entry", () => {
  it("leads a role without settings.manage to the first settings page it may open", async () => {
    asCustomRole(["leave.manageTypes", "users.viewAny"]);
    renderWith(<SidebarNav />);

    expect(
      await screen.findByRole("link", { name: /^settings$/i }),
    ).toHaveAttribute("href", "/settings/leave-types");
  });

  it("is absent for a role with no settings page", async () => {
    asCustomRole(["attendance.viewAll"]);
    renderWith(<SidebarNav />);

    await screen.findByRole("link", { name: /^overtime$/i });
    expect(
      screen.queryByRole("link", { name: /^settings$/i }),
    ).not.toBeInTheDocument();
  });
});

// Attendance Rules is the first entry an HR admin may open, but it lives at
// /attendance/settings, outside the hub, where the settings navigation does
// not render: landing there left every other settings page unreachable
// (audit N98).
describe("the Settings entry for HR", () => {
  it("lands inside the settings hub, not on Attendance Rules", async () => {
    asCustomRole(["attendance.manage", "leave.manageTypes", "users.viewAny"]);
    renderWith(<SidebarNav />);

    expect(
      await screen.findByRole("link", { name: /^settings$/i }),
    ).toHaveAttribute("href", "/settings/leave-types");
  });
});

/**
 * Each persona sees exactly one attendance entry. Holders of
 * attendance.viewAll administer everyone's records from Operations >
 * Attendance > Records; everyone else has My Work > My Attendance. Before
 * 2026-10-16 an administrator saw both a "My Work" row and an Operations
 * group, each labelled "Attendance", and had to learn which was which.
 */
describe("one attendance entry per persona", () => {
  it("gives an administrator Records inside the group, and no My Work row", async () => {
    asCustomRole(["attendance.viewAll"]);
    renderWith(<SidebarNav />);

    // pathname is /attendance/overtime, so the group arrives expanded.
    expect(
      await screen.findByRole("link", { name: /^records$/i }),
    ).toHaveAttribute("href", "/attendance");
    expect(
      screen.queryByRole("link", { name: /my attendance/i }),
    ).not.toBeInTheDocument();
  });

  it("gives an employee My Attendance, and no administration group", async () => {
    asCustomRole(["attendance.checkIn"]);
    renderWith(<SidebarNav />);

    expect(
      await screen.findByRole("link", { name: /my attendance/i }),
    ).toHaveAttribute("href", "/attendance");
    expect(
      screen.queryByRole("button", { name: /^attendance$/i }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: /^records$/i }),
    ).not.toBeInTheDocument();
  });
});

/**
 * No two visible top-level entries may share a label, for any persona: a
 * duplicated word forces the user to open both to learn the difference.
 * Run for the widest menu (a tenant admin holding every gated ability), where
 * a collision is most likely to appear.
 */
describe("no duplicate labels", () => {
  it("renders every top-level entry under a unique name for a full admin", async () => {
    server.use(
      http.get("*/api/v1/auth/me", () =>
        HttpResponse.json({
          user: { public_id: "U1", name: "Admin", role: "tenant_admin" },
          tenant: {
            public_id: "T1",
            name: "Demo",
            timezone: "Africa/Addis_Ababa",
          },
          permissions: [
            "attendance.viewAll",
            "attendance.viewTeam",
            "attendance.viewConflicts",
            "employee.create",
            "org.create",
            "shift.create",
            "device.viewAny",
            "payroll.viewAll",
            "report.generate",
            "settings.manage",
          ],
        }),
      ),
    );
    renderWith(<SidebarNav />);
    await screen.findByRole("link", { name: /^records$/i });

    // Top-level rows are links and group headers directly in the nav; the
    // expanded group's children are indented links and excluded by their
    // padding class.
    const nav = screen.getByRole("navigation");
    const topLevel = Array.from(
      nav.querySelectorAll<HTMLElement>("a, button"),
    ).filter(
      (el) =>
        !el.className.includes("pl-10") &&
        el.textContent &&
        el.textContent.trim().length > 0 &&
        !el.getAttribute("aria-label")?.startsWith("Pin") &&
        !el.getAttribute("aria-label")?.startsWith("Unpin"),
    );
    const labels = topLevel.map((el) => el.textContent!.trim());
    const duplicates = labels.filter((l, i) => labels.indexOf(l) !== i);

    expect(duplicates).toEqual([]);
  });
});

/**
 * The Getting Started card: a single row (link + count) with the progress
 * detail behind an accessible toggle, so new tenants keep the entry without
 * the card holding ~90px of the nav hostage (sidebar audit, 2026-10-16).
 */
describe("the Getting Started card", () => {
  function asFreshTenantAdmin() {
    server.use(
      http.get("*/api/v1/auth/me", () =>
        HttpResponse.json({
          user: { public_id: "U1", name: "Admin", role: "tenant_admin" },
          tenant: {
            public_id: "T1",
            name: "Demo",
            timezone: "Africa/Addis_Ababa",
          },
          permissions: ["settings.manage"],
        }),
      ),
      http.get("*/api/v1/onboarding/progress", () =>
        HttpResponse.json({
          completed_at: null,
          current_step: 3,
          completed_steps: [3],
        }),
      ),
    );
  }

  it("keeps the row a link and the detail behind an aria-expanded toggle", async () => {
    localStorage.removeItem("ethr.setup-banner-open");
    asFreshTenantAdmin();
    renderWith(<SidebarNav />);

    const row = await screen.findByRole("link", { name: /getting started/i });
    expect(row).toHaveAttribute("href", "/setup/guided");

    const toggle = screen.getByRole("button", { name: /setup progress/i });
    expect(toggle).toHaveAttribute("aria-expanded", "true");

    toggle.click();
    await screen.findByRole("button", { name: /setup progress/i });
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    // The choice is remembered per browser.
    expect(localStorage.getItem("ethr.setup-banner-open")).toBe("0");
  });
});
