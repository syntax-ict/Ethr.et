import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { MigrationReadiness } from "@/features/onboarding/v2/components/migration-readiness";
import { MigrationStep } from "@/features/onboarding/v2/components/migration-step";
import type {
  MigrationBatch,
  SignInPlan,
} from "@/features/onboarding/v2/types";

/**
 * What adding a list will do about signing in, said on the review step before
 * anyone is added (owner decision 2026-11-16, the workbook's sign-in column).
 * A row of the list outranks the tick, so the numbers follow both.
 */

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const ID = "01JBQ8Z6ZK3M9V2X7Y4W5T6L09";

const PLAN: SignInPlan = {
  people: 10,
  // Five an invitation reaches: two say yes, one no, two nothing.
  invitable: { yes: 2, no: 1, blank: 2 },
  // Five that can only be activated in person: one yes, one no, three blank.
  in_person: { yes: 1, no: 1, blank: 3 },
  in_person_possible: true,
};

describe("<MigrationReadiness>", () => {
  it("counts who is invited, who is listed for a code and who the list says no to, with the tick on", () => {
    render(<MigrationReadiness plan={PLAN} inviteNow />);

    expect(
      screen.getByText("Invited by email or text when the list is added: 4"),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Listed for a printed code to hand over in person: 4"),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Marked no in the list, so not invited: 2"),
    ).toBeInTheDocument();
    expect(screen.queryByText(/Can be invited with one button/)).toBeNull();
    expect(screen.queryByText(/can only be activated in person/)).toBeNull();
  });

  it("invites only the people marked yes with the tick off, and says the rest can be invited afterwards", () => {
    render(<MigrationReadiness plan={PLAN} inviteNow={false} />);

    expect(
      screen.getByText("Invited by email or text when the list is added: 2"),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        "Can be invited with one button after the list is added: 2",
      ),
    ).toBeInTheDocument();
  });

  it("leaves out the line about people marked no when nobody is", () => {
    render(
      <MigrationReadiness
        plan={{
          ...PLAN,
          invitable: { yes: 0, no: 0, blank: 5 },
          in_person: { yes: 0, no: 0, blank: 5 },
        }}
        inviteNow
      />,
    );

    expect(screen.queryByText(/Marked no in the list/)).toBeNull();
  });

  it("warns, with a link to the Sign-in step, when people can only be activated in person and the organisation does not sign in by employee number", () => {
    render(
      <MigrationReadiness
        plan={{ ...PLAN, in_person_possible: false }}
        inviteNow
      />,
    );

    expect(
      screen.getByText("Some people can only be activated in person"),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/4 people cannot be reached by an invitation/),
    ).toBeVisible();
    expect(
      screen.getByRole("link", { name: "Open the Sign-in step" }),
    ).toHaveAttribute("href", "/setup/guided");
  });

  it("does not warn when everyone can be invited, even if the organisation does not sign in by employee number", () => {
    render(
      <MigrationReadiness
        plan={{
          ...PLAN,
          in_person: { yes: 0, no: 2, blank: 0 },
          in_person_possible: false,
        }}
        inviteNow
      />,
    );

    expect(screen.queryByText(/can only be activated in person/)).toBeNull();
  });
});

function batch(over: Partial<MigrationBatch> = {}): MigrationBatch {
  return {
    public_id: ID,
    source_type: "csv",
    source_ref: null,
    status: "reviewing",
    mode: "add",
    totals: null,
    summary: { new: 1 },
    sign_in_plan: PLAN,
    rows: [
      {
        public_id: `${ID}-ROW`,
        display_name: "Hanna Girma",
        external_identifier: null,
        match_outcome: "new",
        match_confidence: null,
        candidates: [],
        resolved_employee_public_id: null,
        action: "create",
        changes: [],
        can_apply_changes: false,
        scheduled_action: false,
        processed_at: null,
      },
    ],
    ...over,
  };
}

function renderStep(found: MigrationBatch, inviteByDefault = true) {
  server.use(
    http.get("*/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R80", role: "hr_admin" },
        permissions: ["employee.create", "employee.viewAny"],
        tenant: null,
      }),
    ),
    http.get("*/devices", () =>
      HttpResponse.json({ data: [], meta: {}, links: {} }),
    ),
    http.get("*/onboarding/migration/batches", () =>
      HttpResponse.json({ data: [] }),
    ),
    http.get("*/onboarding/migration/batches/:id", () =>
      HttpResponse.json(found),
    ),
  );
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  render(
    <QueryClientProvider client={client}>
      <MigrationStep
        sources="list"
        initialBatchId={ID}
        inviteByDefault={inviteByDefault}
      />
    </QueryClientProvider>,
  );
}

describe("<MigrationStep> readiness card", () => {
  it("is on the review step of a list that adds people, beside the tick, and follows it", async () => {
    renderStep(batch(), false);

    expect(await screen.findByTestId("migration-readiness")).toBeVisible();
    // The tick starts off in the guided setup: only the two marked yes.
    expect(
      screen.getByText("Invited by email or text when the list is added: 2"),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("checkbox", {
        name: /Invite them to sign in as soon as they are added/,
      }),
    ).not.toBeChecked();
  });

  it("is not on the review step of a list that completes people already here", async () => {
    renderStep(batch({ mode: "complete", sign_in_plan: null }));

    expect(await screen.findByText("Hanna Girma")).toBeInTheDocument();
    expect(screen.queryByTestId("migration-readiness")).toBeNull();
  });
});
