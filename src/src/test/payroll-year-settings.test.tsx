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
  });
});

describe("working week", () => {
  it("saves Saturday as a working day", async () => {
    const saved = serve(["settings.manage"]);
    renderWith(<WorkingWeekCard />);
    const user = userEvent.setup();

    const saturday = await screen.findByRole("button", { name: "Sat" });
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
    expect(screen.getByRole("button", { name: "Mon" })).toBeDisabled();
  });
});
