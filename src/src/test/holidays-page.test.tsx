import { afterEach, describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import HolidaysPage from "@/app/(dashboard)/settings/holidays/page";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

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
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
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
      screen.getByRole("button", { name: "Add holiday" }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /^Delete/ }),
    ).not.toBeInTheDocument();
  });

  it("shows delete to a tenant admin", async () => {
    serveHolidays([holiday(1, { name: "Genna" })]);
    server.use(me("tenant_admin", [...HR_ADMIN, "holiday.delete"]));
    renderPage();

    await screen.findByText("Genna");
    expect(
      screen.getByRole("button", { name: /^Delete Genna/ }),
    ).toBeInTheDocument();
  });

  // `PUT /holidays/{id}` had no screen: correcting a holiday meant deleting it
  // (tenant admin only) and adding it again (audit N99).
  it("lets HR correct a holiday's name and date in place", async () => {
    let body: Record<string, unknown> | null = null;
    let path = "";
    serveHolidays([holiday(1, { name: "Genna", date: "2026-01-07" })]);
    server.use(
      me("hr_admin", HR_ADMIN),
      http.put("*/api/v1/holidays/:id", async ({ request, params }) => {
        path = String(params.id);
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(holiday(1, body));
      }),
    );
    renderPage();
    const user = userEvent.setup();

    await user.click(
      await screen.findByRole("button", { name: /^Edit Genna/ }),
    );
    const dialog = await screen.findByRole("dialog");
    const name = within(dialog).getByRole("textbox", { name: /name/i });
    expect(name).toHaveValue("Genna");
    await user.clear(name);
    await user.type(name, "Ethiopian Christmas");
    await user.click(within(dialog).getByRole("button", { name: /^Save/ }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(path).toBe("HOL1");
    expect(body).toMatchObject({
      name: "Ethiopian Christmas",
      date: "2026-01-07",
    });
  });

  // Eid al-Fitr, Eid al-Adha and Mawlid carry `is_estimated: true` and the
  // table showed nothing (settings review F5, O-3).
  describe("estimated dates", () => {
    it("marks only the estimated holidays", async () => {
      serveHolidays([
        holiday(1, { name: "Genna", date: "2027-01-07" }),
        holiday(2, {
          name: "Eid al-Fitr",
          date: "2027-03-10",
          is_estimated: true,
        }),
      ]);
      server.use(me("hr_admin", HR_ADMIN));
      renderPage();

      await screen.findByText("Eid al-Fitr");
      expect(screen.getAllByText("Estimated")).toHaveLength(1);
      const row = screen.getByText("Eid al-Fitr").closest("tr") as HTMLElement;
      expect(within(row).getByText("Estimated")).toBeInTheDocument();
      expect(screen.getByText(/follow the moon/i)).toBeInTheDocument();
    });

    it("shows neither the badge nor the helper line when none are estimated", async () => {
      serveHolidays([holiday(1, { name: "Genna", date: "2027-01-07" })]);
      server.use(me("hr_admin", HR_ADMIN));
      renderPage();

      await screen.findByText("Genna");
      expect(screen.queryByText("Estimated")).not.toBeInTheDocument();
      expect(screen.queryByText(/follow the moon/i)).not.toBeInTheDocument();
    });
  });

  // Holidays stopped at 2027-09-28 and nothing prompted for 2028 (F5).
  describe("next year's holidays", () => {
    afterEach(() => {
      vi.useRealTimers();
    });

    // Only `Date` is faked: msw and react-query still need real timers.
    function today(iso: string) {
      vi.useFakeTimers({ toFake: ["Date"] });
      vi.setSystemTime(new Date(`${iso}T09:00:00Z`));
    }

    it("warns when the last holiday is within 90 days", async () => {
      today("2027-07-15");
      serveHolidays([holiday(1, { name: "Meskel", date: "2027-09-28" })]);
      server.use(me("hr_admin", HR_ADMIN));
      renderPage();

      await screen.findByText("Meskel");
      expect(
        screen.getByText("Holidays for 2028 have not been added yet"),
      ).toBeInTheDocument();
      expect(
        screen.getByText(/HR admins and organisation admins can add them/),
      ).toBeInTheDocument();
    });

    it("ignores recurring holidays, which repeat by themselves", async () => {
      today("2027-09-01");
      serveHolidays([
        holiday(1, { name: "Meskel", date: "2027-09-28", recurring: true }),
      ]);
      server.use(me("hr_admin", HR_ADMIN));
      renderPage();

      await screen.findByText("Meskel");
      expect(
        screen.queryByText(/have not been added yet/),
      ).not.toBeInTheDocument();
    });

    it("counts one-time holidays even when recurring ones run later", async () => {
      today("2027-09-01");
      serveHolidays([
        holiday(1, { name: "Eid al-Adha", date: "2027-09-20" }),
        holiday(2, { name: "Genna", date: "2028-01-07", recurring: true }),
      ]);
      server.use(me("hr_admin", HR_ADMIN));
      renderPage();

      await screen.findByText("Genna");
      expect(
        screen.getByText("Holidays for 2028 have not been added yet"),
      ).toBeInTheDocument();
    });

    it("stays quiet when the list reaches further than 90 days", async () => {
      today("2027-06-01");
      serveHolidays([holiday(1, { name: "Meskel", date: "2027-09-28" })]);
      server.use(me("hr_admin", HR_ADMIN));
      renderPage();

      await screen.findByText("Meskel");
      expect(
        screen.queryByText(/have not been added yet/),
      ).not.toBeInTheDocument();
    });

    it("stays quiet once a later year is listed", async () => {
      today("2027-09-01");
      serveHolidays([
        holiday(1, { name: "Meskel", date: "2027-09-28" }),
        holiday(2, { name: "Genna 2028", date: "2028-01-07" }),
      ]);
      server.use(me("hr_admin", HR_ADMIN));
      renderPage();

      await screen.findByText("Genna 2028");
      expect(
        screen.queryByText(/have not been added yet/),
      ).not.toBeInTheDocument();
    });
  });

  // One click deleted a holiday from every leave and attendance calculation.
  it("asks before deleting, and deletes nothing if cancelled", async () => {
    let deletes = 0;
    serveHolidays([holiday(1, { name: "Genna" })]);
    server.use(
      me("tenant_admin", [...HR_ADMIN, "holiday.delete"]),
      http.delete("*/api/v1/holidays/:id", () => {
        deletes += 1;
        return new HttpResponse(null, { status: 204 });
      }),
    );
    renderPage();
    const user = userEvent.setup();

    await user.click(
      await screen.findByRole("button", { name: /^Delete Genna/ }),
    );
    const confirm = await screen.findByRole("dialog");
    await user.click(within(confirm).getByRole("button", { name: /cancel/i }));
    expect(deletes).toBe(0);

    await user.click(screen.getByRole("button", { name: /^Delete Genna/ }));
    await user.click(
      within(await screen.findByRole("dialog")).getByRole("button", {
        name: /^Delete$/,
      }),
    );
    await waitFor(() => expect(deletes).toBe(1));
  });
});
