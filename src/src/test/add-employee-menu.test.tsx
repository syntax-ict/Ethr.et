import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { AddEmployeeMenu } from "@/features/employees/components/add-employee-menu";

/**
 * "Add Employee" opens every way in (2026-11-12). The terminal and the badge
 * items are offered only when they lead somewhere, and only to someone who
 * may list devices; nobody else's page load asks for them.
 */
const perms = vi.hoisted(() => ({
  can: { viewDevices: true, manageAttendance: true } as Record<string, boolean>,
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    can: perms.can,
    hasPermission: () => false,
    role: "hr_admin",
  }),
}));

let asked: string[] = [];

function serve(devices: unknown[], badges: unknown[]) {
  server.use(
    http.get("*/api/v1/devices", () => {
      asked.push("devices");
      return HttpResponse.json({
        data: devices,
        meta: { last_page: 1 },
        links: {},
      });
    }),
    http.get("*/api/v1/devices/unmatched-punches", () => {
      asked.push("badges");
      return HttpResponse.json({ data: badges });
    }),
  );
}

let queryClient: QueryClient;

function renderMenu(variant?: "split" | "others") {
  queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<AddEmployeeMenu variant={variant} />, { wrapper: Wrapper });
}

/**
 * Whether a query under `key` was ever started. A disabled query sits in the
 * cache idle with nothing fetched; one that was allowed to run is fetching,
 * or has data or an error, from the moment it mounts. Asked of the cache
 * rather than of the network, so a request that has not landed yet cannot
 * make a missing guard pass.
 */
function started(key: string): boolean {
  return queryClient
    .getQueryCache()
    .findAll({ queryKey: ["devices"] })
    .filter((q) => JSON.stringify(q.queryKey).includes(key))
    .some(
      (q) =>
        q.state.fetchStatus !== "idle" ||
        q.state.dataUpdatedAt > 0 ||
        q.state.errorUpdatedAt > 0,
    );
}

const BADGE = {
  device_public_id: "01HZDEV0000000000000000001",
  device_name: "Main gate",
  badge: "7777",
  punches: 2,
  first_seen: "2026-10-14 05:01:22",
  last_seen: "2026-10-14 14:05:00",
};

async function openMenu(name = "More ways to add") {
  await userEvent.click(screen.getByRole("button", { name }));
}

describe("<AddEmployeeMenu>", () => {
  beforeEach(() => {
    asked = [];
    perms.can = { viewDevices: true, manageAttendance: true };
  });

  it("keeps one click to the form, and lists every way in", async () => {
    serve(
      [{ public_id: "D1", name: "Gate", receives_only: false }],
      [BADGE, { ...BADGE, badge: "8888" }],
    );
    renderMenu();

    expect(screen.getByRole("link", { name: "Add Employee" })).toHaveAttribute(
      "href",
      "/employees/new",
    );

    await vi.waitFor(() =>
      expect(asked).toEqual(expect.arrayContaining(["devices", "badges"])),
    );
    await openMenu();

    expect(
      await screen.findByRole("menuitem", { name: "One person" }),
    ).toHaveAttribute("href", "/employees/new");
    expect(
      screen.getByRole("menuitem", { name: "From a file or pasted rows" }),
    ).toHaveAttribute("href", "/employees/import");
    expect(
      screen.getByRole("menuitem", { name: "From an attendance terminal" }),
    ).toHaveAttribute("href", "/employees/from-device");
    expect(
      screen.getByRole("menuitem", { name: "Unrecognised badges (2)" }),
    ).toHaveAttribute("href", "/devices#unmatched");
  });

  it("leaves out the terminal when no device can be read, and the badges when there are none", async () => {
    serve([{ public_id: "D1", name: "Gate", receives_only: true }], []);
    renderMenu();

    await vi.waitFor(() =>
      expect(asked).toEqual(expect.arrayContaining(["devices", "badges"])),
    );
    await openMenu();

    expect(
      await screen.findByRole("menuitem", {
        name: "From a file or pasted rows",
      }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("menuitem", { name: "From an attendance terminal" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("menuitem", { name: /Unrecognised badges/ }),
    ).not.toBeInTheDocument();
  });

  it("does not offer or ask for the badges without both permissions", async () => {
    perms.can = { viewDevices: true, manageAttendance: false };
    serve([{ public_id: "D1", name: "Gate", receives_only: false }], [BADGE]);
    renderMenu();
    expect(started("unmatched-punches")).toBe(false);

    await vi.waitFor(() => expect(asked).toContain("devices"));
    await openMenu();

    expect(
      await screen.findByRole("menuitem", {
        name: "From an attendance terminal",
      }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("menuitem", { name: /Unrecognised badges/ }),
    ).not.toBeInTheDocument();
    expect(started("unmatched-punches")).toBe(false);
    expect(asked).not.toContain("badges");
  });

  it("asks for nothing about devices from someone who may not list them", async () => {
    perms.can = { viewDevices: false, manageAttendance: true };
    serve([{ public_id: "D1", name: "Gate", receives_only: false }], [BADGE]);
    renderMenu();
    // Mounted with both queries in the cache, neither started.
    expect(
      queryClient.getQueryCache().findAll({ queryKey: ["devices"] }),
    ).toHaveLength(2);
    expect(started("")).toBe(false);

    await openMenu();

    expect(
      await screen.findByRole("menuitem", {
        name: "From a file or pasted rows",
      }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("menuitem", { name: "From an attendance terminal" }),
    ).not.toBeInTheDocument();
    expect(started("")).toBe(false);
    expect(asked).toEqual([]);
  });

  it("on the form itself, offers the other ways and not the form again", async () => {
    serve([], []);
    renderMenu("others");

    await openMenu("Other ways to add");

    expect(
      await screen.findByRole("menuitem", {
        name: "From a file or pasted rows",
      }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("menuitem", { name: "One person" }),
    ).not.toBeInTheDocument();
  });
});
