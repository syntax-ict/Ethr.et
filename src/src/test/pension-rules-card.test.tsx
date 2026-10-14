import { describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { PensionRulesCard } from "@/features/payroll/components/pension-rules-card";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

const granted = vi.hoisted(() => ({ manage: true }));
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    hasPermission: (p: string) =>
      p !== "payroll.manageConfig" || granted.manage,
  }),
}));

/**
 * Pension rules as data (2026-10-14): the law in force, its history, and a
 * new dated rule that cannot be saved without saying where it comes from.
 */
const RULE = {
  public_id: "R1",
  owner: "platform",
  scheme: "private",
  employee_rate: 7,
  employer_rate: 11,
  ceiling_cents: null,
  floor_cents: null,
  base: "basic",
  exempt_non_nationals: true,
  min_service_days: null,
  legal_reference: "Proclamation No. 1268/2022, Art. 10",
  effective_from: "2022-03-18",
  effective_to: null,
};

function serve() {
  const posted: unknown[] = [];
  server.use(
    http.get("*/api/v1/payroll/pension-rules", () =>
      HttpResponse.json({
        data: [RULE],
        scheme: "private",
        in_force: { ...RULE, source: "platform", rule_public_id: "R1" },
      }),
    ),
    http.post("*/api/v1/payroll/pension-rules", async ({ request }) => {
      posted.push(await request.json());
      return HttpResponse.json({ ...RULE, public_id: "R2" }, { status: 201 });
    }),
  );
  return posted;
}

function renderCard() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>
        <PensionRulesCard />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

describe("<PensionRulesCard>", () => {
  it("shows the rule in force and where it comes from", async () => {
    serve();
    renderCard();

    expect(
      await screen.findByText(/In force today .*employee 7%, employer 11%/),
    ).toBeInTheDocument();
    expect(
      screen.getAllByText("Proclamation No. 1268/2022, Art. 10").length,
    ).toBeGreaterThan(0);
    expect(screen.getByText("ETHR default")).toBeInTheDocument();
  });

  it("adds a dated rule only once its legal source is given", async () => {
    const posted = serve();
    granted.manage = true;
    const user = userEvent.setup();
    renderCard();

    await user.click(
      await screen.findByRole("button", { name: /add a rule/i }),
    );
    await user.type(screen.getByLabelText("Employee %"), "8");
    await user.type(screen.getByLabelText("Employer %"), "12");
    const save = screen.getByRole("button", { name: /save rule/i });
    expect(save).toBeDisabled();

    await user.type(
      screen.getByLabelText("Law or source (required)"),
      "Proclamation No. 9999/2027, Art. 10",
    );
    await user.click(save);

    await waitFor(() => expect(posted).toHaveLength(1));
    expect(posted[0]).toMatchObject({
      scheme: "private",
      employee_rate: 8,
      employer_rate: 12,
      ceiling_cents: null,
      legal_reference: "Proclamation No. 9999/2027, Art. 10",
    });
  });

  it("offers no Add to someone who may only view payroll configuration", async () => {
    serve();
    granted.manage = false;
    renderCard();

    await screen.findByText(/In force today/);
    expect(
      screen.queryByRole("button", { name: /add a rule/i }),
    ).not.toBeInTheDocument();
    granted.manage = true;
  });
});
