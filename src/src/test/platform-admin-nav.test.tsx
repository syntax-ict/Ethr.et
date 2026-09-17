import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { SidebarNav } from "@/components/layouts/sidebar-nav";
import { getRouteMeta } from "@/lib/route-meta";

vi.mock("next/navigation", () => ({
  usePathname: () => "/admin",
}));

const role = vi.hoisted(() => ({ value: "super_admin" }));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => {
    const isSuperAdmin = role.value === "super_admin";
    return {
      role: role.value,
      level: isSuperAdmin ? 100 : 90,
      permissions: [],
      hasPermission: () => isSuperAdmin,
      isAtLeast: () => true,
      hasRole: (...roles: string[]) => roles.includes(role.value),
      isSuperAdmin,
      isTenantAdmin: true,
      isHrAdmin: true,
      isFinanceAdmin: false,
      isSupervisor: true,
      isEmployee: false,
      can: new Proxy({} as Record<string, boolean>, {
        get: () => true,
      }),
    };
  },
}));

function renderNav() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <SidebarNav />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  role.value = "super_admin";
});

describe("sidebar navigation for the platform super admin", () => {
  it("shows the console destinations and none of the tenant ones", () => {
    renderNav();

    expect(
      screen.getByRole("link", { name: /admin console/i }),
    ).toHaveAttribute("href", "/admin");
    expect(screen.getByRole("link", { name: /tenants/i })).toHaveAttribute(
      "href",
      "/admin/tenants",
    );
    expect(
      screen.getByRole("link", { name: /platform settings/i }),
    ).toBeInTheDocument();
    // The plan catalog. A screen that is not in the nav is a screen nobody
    // finds: the plan this implements lists six registration points precisely
    // because adding a route is the easy half.
    expect(screen.getByRole("link", { name: /^plans$/i })).toHaveAttribute(
      "href",
      "/admin/plans",
    );

    // A super admin has no tenant, so these lead nowhere useful — and on the
    // platform hostname the middleware bounces each one back to /admin.
    expect(
      screen.queryByRole("link", { name: /^my attendance$/i }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: /^employees$/i }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: /^payroll runs$/i }),
    ).not.toBeInTheDocument();
  });

  it("still gives a tenant admin the full tenant navigation", () => {
    role.value = "tenant_admin";
    renderNav();

    expect(
      screen.getByRole("link", { name: /^employees$/i }),
    ).toBeInTheDocument();
  });
});

describe("route metadata for detail pages", () => {
  it("never turns an opaque record id into a page heading", () => {
    // The last URL segment used to become the <h1> unconditionally, so every
    // detail route rendered a 26-character ULID as its title.
    expect(getRouteMeta("/admin/tenants/01M0H81PEVP5T5DQJ093XNSAY2")).toEqual(
      getRouteMeta("/admin/tenants"),
    );
    expect(
      getRouteMeta("/employees/01M0C0BBMH6CC238XTVJWP6QWE")?.label,
    ).not.toMatch(/^01/);
    expect(getRouteMeta("/payroll/12345")?.label ?? "").not.toBe("12345");
  });

  it("still resolves a real named sub-route", () => {
    expect(getRouteMeta("/admin/platform-settings")?.label).toBe(
      "Platform Settings",
    );
  });

  it("names the plan catalog rather than falling back to the segment", () => {
    // Without a route-meta entry the segment fallback takes over and the
    // heading reads "Plans" while the description is inherited from /admin —
    // which is how three console screens once described themselves
    // identically.
    const meta = getRouteMeta("/admin/plans");
    expect(meta?.label).toBe("Plans");
    expect(meta?.description).toBe(
      "Prices, limits and copy the public pricing page reads",
    );
  });
});
