import { describe, expect, it } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import type { ReactNode } from "react";
import { server } from "./msw/server";
import { PayrollScheduleCard } from "@/features/payroll/components/payroll-schedule-card";
import { WorkingWeekCard } from "@/features/leave/components/working-week-card";

const SETTINGS = {
  leave: { working_days: [1, 2, 3, 4, 5] },
  payroll: {
    pay_period: "monthly",
    run_day: 25,
    fiscal_year_start_month: 1,
    pagumen_proration_strategy: "full_month",
    working_days_per_month: 22,
    retirement_age: 60,
  },
};

function serve(permissions: string[]) {
  const saved: Record<string, unknown>[] = [];
  server.use(
    http.get("*/api/v1/settings", () => HttpResponse.json(SETTINGS)),
    http.put("*/api/v1/settings", async ({ request }) => {
      const body = (await request.json()) as {
        settings: Record<string, unknown>;
      };
      saved.push(body.settings);
      return HttpResponse.json({});
    }),
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Admin", role: "tenant_admin" },
        tenant: { public_id: "T1", name: "Demo" },
        permissions,
      }),
    ),
  );
  return saved;
}

function renderWith(node: ReactNode) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{node}</QueryClientProvider>,
  );
}

// The API accepted and used these settings, but no screen set them, so every
// organisation ran on the defaults (audit N82).
describe("payroll year settings", () => {
  it("saves a Hamle fiscal year and daily-rate Pagume", async () => {
    const saved = serve(["settings.manage"]);
    renderWith(<PayrollScheduleCard />);
    const user = userEvent.setup();

    await user.click(
      await screen.findByRole("combobox", { name: /fiscal year starts/i }),
    );
    await user.click(await screen.findByRole("option", { name: "Hamle" }));
    await user.click(screen.getByRole("combobox", { name: /pagume pay/i }));
    await user.click(
      await screen.findByRole("option", { name: /daily rate/i }),
    );
    await user.click(screen.getByRole("button", { name: /save/i }));

    await waitFor(() => expect(saved).toHaveLength(1));
    expect(saved[0]).toMatchObject({
      fiscal_year_start_month: 11,
      pagumen_proration_strategy: "daily_rate",
      run_day: 25,
      retirement_age: 60,
    });
    // Untouched, the month length stays the platform's: saving something else
    // must not pin the organisation to today's default.
    expect(saved[0]).not.toHaveProperty("working_days_per_month");
  });

  it("pins no statutory value when only the run day changes", async () => {
    const saved = serve(["settings.manage"]);
    renderWith(<PayrollScheduleCard />);
    const user = userEvent.setup();

    const runDay = await screen.findByLabelText(/payroll run day/i);
    await user.clear(runDay);
    await user.type(runDay, "27");
    await user.click(screen.getByRole("button", { name: /save/i }));

    await waitFor(() => expect(saved).toHaveLength(1));
    expect(saved[0]).toMatchObject({ run_day: 27 });
    // Each of these would become the organisation's own value and stop
    // following its kind (K-56).
    expect(saved[0]).not.toHaveProperty("fiscal_year_start_month");
    expect(saved[0]).not.toHaveProperty("pagumen_proration_strategy");
    expect(saved[0]).not.toHaveProperty("working_days_per_month");
  });

  it("saves the organisation's own working days per month", async () => {
    const saved = serve(["settings.manage"]);
    renderWith(<PayrollScheduleCard />);
    const user = userEvent.setup();

    const days = await screen.findByLabelText(/working days per month/i);
    expect(days).toHaveValue(22);
    await user.clear(days);
    await user.type(days, "26");
    await user.click(screen.getByRole("button", { name: /save/i }));

    await waitFor(() => expect(saved).toHaveLength(1));
    expect(saved[0]).toMatchObject({ working_days_per_month: 26 });
  });

  it("refuses a month length outside 20 to 31", async () => {
    const saved = serve(["settings.manage"]);
    renderWith(<PayrollScheduleCard />);
    const user = userEvent.setup();

    const days = await screen.findByLabelText(/working days per month/i);
    await user.clear(days);
    await user.type(days, "5");
    await user.click(screen.getByRole("button", { name: /save/i }));

    expect(await screen.findByText(/from 20 to 31/i)).toBeInTheDocument();
    expect(saved).toHaveLength(0);
  });
});

describe("working week", () => {
  it("saves Saturday as a working day", async () => {
    const saved = serve(["settings.manage"]);
    renderWith(<WorkingWeekCard />);
    const user = userEvent.setup();

    const saturday = await screen.findByRole("button", { name: "Saturday" });
    expect(saturday).toHaveAttribute("aria-pressed", "false");
    await waitFor(() => expect(saturday).toBeEnabled());
    await user.click(saturday);
    await user.click(screen.getByRole("button", { name: /save/i }));

    await waitFor(() => expect(saved).toHaveLength(1));
    expect(saved[0]).toEqual({ working_days: [1, 2, 3, 4, 5, 6] });
  });

  it("is read-only without settings.manage", async () => {
    serve(["leave.manageTypes"]);
    renderWith(<WorkingWeekCard />);

    expect(
      await screen.findByText(/only an organisation admin/i),
    ).toBeInTheDocument();
    // Awaited: the note shows once permissions are known, the days once the
    // settings arrive. A spare re-render in `useT` used to hide the gap.
    expect(
      await screen.findByRole("button", { name: "Monday" }),
    ).toBeDisabled();
  });
});

/**
 * On a failed load the card used to draw its form from defaults (run day 25,
 * January fiscal year). Saving it would have written those defaults over the
 * organisation's real schedule. It now says the data did not load, and shows
 * no form to save.
 */
describe("payroll schedule card when settings do not load", () => {
  it("shows the error, not a form of defaults", async () => {
    server.use(
      http.get("*/api/v1/settings", () =>
        HttpResponse.json({ detail: "Server Error" }, { status: 500 }),
      ),
      http.get("*/api/v1/auth/me", () =>
        HttpResponse.json({
          user: { public_id: "U1", name: "Admin", role: "tenant_admin" },
          tenant: { public_id: "T1", name: "Demo" },
          permissions: ["settings.manage"],
        }),
      ),
    );
    renderWith(<PayrollScheduleCard />);

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Couldn't load this",
    );
    expect(screen.queryByRole("combobox")).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /save/i }),
    ).not.toBeInTheDocument();
  });
});

describe("working week card when settings do not load", () => {
  it("shows the error, not a Monday-to-Friday default that could be saved", async () => {
    server.use(
      http.get("*/api/v1/settings", () =>
        HttpResponse.json({ detail: "Server Error" }, { status: 500 }),
      ),
      http.get("*/api/v1/auth/me", () =>
        HttpResponse.json({
          user: { public_id: "U1", name: "Admin", role: "tenant_admin" },
          tenant: { public_id: "T1", name: "Demo" },
          permissions: ["settings.manage"],
        }),
      ),
    );
    renderWith(<WorkingWeekCard />);

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Couldn't load this",
    );
    expect(screen.queryByRole("group")).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /save/i }),
    ).not.toBeInTheDocument();
  });
});
