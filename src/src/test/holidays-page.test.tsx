import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import HolidaysPage from "@/app/(dashboard)/settings/holidays/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/** Shaped like HolidayResource. */
function holiday(n: number, overrides: Record<string, unknown> = {}) {
  const day = String((n % 28) + 1).padStart(2, "0");
  const month = String(Math.floor(n / 28) + 1).padStart(2, "0");
  return {
    public_id: `HOL${n}`,
    name: `Holiday ${n}`,
    name_am: null,
    date: `2025-${month}-${day}`,
    branch_public_id: null,
    ethiopian_calendar: false,
    recurring: false,
    is_estimated: false,
    is_active: true,
    created_at: "2025-01-01T00:00:00Z",
    updated_at: "2025-01-01T00:00:00Z",
    ...overrides,
  };
}

/**
 * Served the way `HolidayController::index` serves them: oldest first, 25 a
 * page unless asked, at most 100.
 */
function serveHolidays(rows: unknown[]) {
  server.use(
    http.get("*/api/v1/holidays", ({ request }) => {
      const url = new URL(request.url);
      const page = Number(url.searchParams.get("page") ?? 1);
      const perPage = Math.min(
        Number(url.searchParams.get("per_page") ?? 25),
        100,
      );
      const from = (page - 1) * perPage;
      return HttpResponse.json({
        data: rows.slice(from, from + perPage),
        meta: {
          current_page: page,
          last_page: Math.max(1, Math.ceil(rows.length / perPage)),
          per_page: perPage,
          total: rows.length,
        },
        links: {},
      });
    }),
  );
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

/** What PermissionSeeder grants hr_admin for holidays — no `holiday.delete`. */
const HR_ADMIN = [
  "holiday.viewAny",
  "holiday.view",
  "holiday.create",
  "holiday.update",
];

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<HolidaysPage />, { wrapper: Wrapper });
}

describe("<HolidaysPage>", () => {
  it("shows this year's holidays once more than 25 are on record", async () => {
    // Oldest first, 25 a page: a tenant with last year's holidays on record
    // saw only those, and never the ones coming up.
    const lastYear = Array.from({ length: 25 }, (_, i) => holiday(i));
    const thisYear = holiday(99, {
      name: "Meskel 2026",
      date: "2026-09-27",
    });
    serveHolidays([...lastYear, thisYear]);
    server.use(me("tenant_admin", [...HR_ADMIN, "holiday.delete"]));
    renderPage();

    expect(await screen.findByText("Meskel 2026")).toBeInTheDocument();
  });

  it("offers delete only to a role that may delete", async () => {
    // Deleting is `holiday.delete` (tenant admin); HR admins add holidays but
    // cannot remove them, so their delete always came back 403.
    serveHolidays([holiday(1, { name: "Genna" })]);
    server.use(me("hr_admin", HR_ADMIN));
    renderPage();

    await screen.findByText("Genna");
    expect(
      screen.getByRole("button", { name: /Add Holiday/ }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Delete" }),
    ).not.toBeInTheDocument();
  });

  it("shows delete to a tenant admin", async () => {
    serveHolidays([holiday(1, { name: "Genna" })]);
    server.use(me("tenant_admin", [...HR_ADMIN, "holiday.delete"]));
    renderPage();

    await screen.findByText("Genna");
    expect(screen.getByRole("button", { name: "Delete" })).toBeInTheDocument();
  });
});
