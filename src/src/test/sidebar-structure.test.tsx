import { describe, expect, it, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { SidebarNav } from "@/components/layouts/sidebar-nav";

vi.mock("next/navigation", () => ({
  usePathname: () => "/dashboard",
}));

/**
 * The sidebar's sections are named for what a person is doing (2026-10-23):
 * Workspace, People, Operations, Finance, Insights, Communication, Admin.
 * Reports and Analytics used to sit under Finance, so an HR administrator
 * without payroll saw a "Finance" heading over two rows that were not finance.
 */
function signedInAs(role: string, permissions: string[]) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Test", role },
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

function headings(nav: HTMLElement): string[] {
  return Array.from(nav.querySelectorAll("p"))
    .map((p) => p.textContent?.trim() ?? "")
    .filter(Boolean);
}

/** The heading a link sits under, read from the section that holds it. */
function sectionOf(link: HTMLElement): string | undefined {
  return link
    .closest("div.flex.flex-col.gap-0\\.5")
    ?.querySelector("p")
    ?.textContent?.trim();
}

describe("sidebar sections", () => {
  it("names each section for the work in it, in one order", async () => {
    signedInAs("tenant_admin", [
      "attendance.viewAll",
      "attendance.viewTeam",
      "attendance.viewConflicts",
      "employee.create",
      "org.create",
      "shift.create",
      "device.viewAny",
      "payroll.viewAll",
      "report.generate",
      "dashboard.executive",
      "settings.manage",
    ]);
    renderNav();
    await screen.findByRole("link", { name: /^reports$/i });

    expect(headings(screen.getByRole("navigation"))).toEqual([
      "Workspace",
      "People",
      "Operations",
      "Finance",
      "Insights",
      "Communication",
      "Admin",
    ]);
    expect(sectionOf(screen.getByRole("link", { name: /^reports$/i }))).toBe(
      "Insights",
    );
    expect(
      sectionOf(screen.getByRole("link", { name: /^announcements$/i })),
    ).toBe("Communication");
    expect(sectionOf(screen.getByRole("link", { name: /^vacancies$/i }))).toBe(
      "People",
    );
  });

  it("gathers payroll into one Payroll group under Finance", async () => {
    signedInAs("finance_admin", ["payroll.viewAll"]);
    const user = userEvent.setup();
    renderNav();

    const group = await screen.findByRole("button", { name: /^payroll$/i });
    expect(group).toHaveAttribute("aria-expanded", "false");
    await user.click(group);
    expect(screen.getByRole("link", { name: /^runs$/i })).toHaveAttribute(
      "href",
      "/payroll",
    );
    expect(screen.getByRole("link", { name: /^loans$/i })).toHaveAttribute(
      "href",
      "/payroll/loans",
    );
    expect(
      screen.getByRole("link", { name: /^cost sharing$/i }),
    ).toHaveAttribute("href", "/payroll/cost-sharing");
  });

  it("gives an HR administrator without payroll no Finance heading at all", async () => {
    signedInAs("hr_admin", ["employee.create", "report.generate"]);
    renderNav();
    await screen.findByRole("link", { name: /^reports$/i });

    const titles = headings(screen.getByRole("navigation"));
    expect(titles).not.toContain("Finance");
    expect(titles).toContain("Insights");
  });
});

describe("attendance anomaly count", () => {
  it("shows punches waiting for review on the closed group, and on Integrity once open", async () => {
    signedInAs("hr_admin", ["attendance.viewAll", "attendance.viewConflicts"]);
    server.use(
      http.get("*/api/v1/attendance/integrity/summary", () =>
        HttpResponse.json({ open: 4, accepted: 0, rejected: 0 }),
      ),
    );
    const user = userEvent.setup();
    renderNav();

    // The group header is the expandable button, not a pin toggle.
    const group = await screen.findByRole("button", {
      name: /^attendance/i,
      expanded: false,
    });
    expect(await within(group).findByText("4")).toBeInTheDocument();

    await user.click(group);
    const integrity = screen.getByRole("link", { name: /integrity/i });
    expect(within(integrity).getByText("4")).toBeInTheDocument();
    // Open, the count moves to the row it belongs to.
    expect(within(group).queryByText("4")).toBeNull();
  });

  it("asks for the count only for someone who may review the flags", async () => {
    const hits: string[] = [];
    signedInAs("employee", []);
    server.use(
      http.get("*/api/v1/attendance/integrity/summary", ({ request }) => {
        hits.push(request.url);
        return HttpResponse.json({ open: 9 });
      }),
    );
    renderNav();
    await screen.findByRole("link", { name: /^leave$/i });
    await new Promise((r) => setTimeout(r, 200));
    expect(hits).toEqual([]);
  });
});
