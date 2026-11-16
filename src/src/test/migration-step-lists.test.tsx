import { describe, it, expect, vi } from "vitest";
import { toast } from "sonner";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { MigrationStep } from "@/features/onboarding/v2/components/migration-step";
import type {
  MigrationBatch,
  MigrationListInProgress,
} from "@/features/onboarding/v2/types";

/**
 * A list in progress is opened exactly as one just staged is: the step is
 * given its id, and the screen for the list's state takes over. These tests
 * are about that hand-over, and about a page that keeps the open list in its
 * address (`initialBatchId`, `onBatchChange`).
 */

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const REVIEWING_ID = "01JBQ8Z6ZK3M9V2X7Y4W5T6L01";
const STAGING_ID = "01JBQ8Z6ZK3M9V2X7Y4W5T6L02";

function batch(id: string, over: Partial<MigrationBatch> = {}): MigrationBatch {
  return {
    public_id: id,
    source_type: "csv",
    source_ref: null,
    status: "reviewing",
    mode: "add",
    totals: null,
    summary: { new: 1 },
    rows: [
      {
        public_id: `${id}-ROW`,
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

function inProgress(
  id: string,
  status: MigrationListInProgress["status"],
): MigrationListInProgress {
  return {
    public_id: id,
    status,
    mode: "add",
    source_type: "csv",
    rows: 1,
    created_at: new Date(Date.now() - 60_000 * 5).toISOString(),
    updated_at: new Date(Date.now() - 60_000 * 5).toISOString(),
    created_by_name: null,
    mine: true,
  };
}

function me(permissions: string[]) {
  return http.get("*/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R80", role: "hr_admin" },
      permissions,
      tenant: null,
    }),
  );
}

function setup(lists: MigrationListInProgress[], batches: MigrationBatch[]) {
  server.use(
    me(["employee.create", "employee.viewAny"]),
    http.get("*/devices", () =>
      HttpResponse.json({ data: [], meta: {}, links: {} }),
    ),
    http.get("*/onboarding/migration/batches", () =>
      HttpResponse.json({ data: lists }),
    ),
    http.get("*/onboarding/migration/batches/:id", ({ params }) => {
      const found = batches.find((b) => b.public_id === params.id);
      return found
        ? HttpResponse.json(found)
        : HttpResponse.json(
            { status: 404, title: "Not Found" },
            { status: 404 },
          );
    }),
  );
}

function renderStep(props: Partial<Parameters<typeof MigrationStep>[0]> = {}) {
  const onBatchChange = vi.fn();
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  render(
    <QueryClientProvider client={client}>
      <MigrationStep sources="list" onBatchChange={onBatchChange} {...props} />
    </QueryClientProvider>,
  );
  return { onBatchChange };
}

describe("<MigrationStep> lists in progress", () => {
  it("shows the lists above the box, and opens one as if it had just been staged", async () => {
    setup([inProgress(REVIEWING_ID, "reviewing")], [batch(REVIEWING_ID)]);
    const { onBatchChange } = renderStep();

    const row = await screen.findByTestId("list-in-progress");
    expect(screen.getByText("Lists in progress")).toBeInTheDocument();
    // The box a new list goes into is still there beneath it.
    expect(screen.getByText("Stage for review")).toBeInTheDocument();

    await userEvent.click(within(row).getByRole("button", { name: "Open" }));

    expect(await screen.findByText("Hanna Girma")).toBeInTheDocument();
    expect(onBatchChange).toHaveBeenCalledWith(REVIEWING_ID);
    // A list is open: the card steps aside.
    expect(screen.queryByText("Lists in progress")).toBeNull();
    expect(screen.queryByText("Stage for review")).toBeNull();
  });

  it("carries on a list still being read before showing it, by the request a stopped staging uses", async () => {
    setup(
      [inProgress(STAGING_ID, "staging")],
      [batch(STAGING_ID, { status: "reviewing" })],
    );
    let slices = 0;
    server.use(
      http.post("*/onboarding/migration/batches/:id/stage", () => {
        slices += 1;
        return HttpResponse.json({
          public_id: STAGING_ID,
          status: "reviewing",
          staged: 1,
          remaining: 0,
        });
      }),
    );
    const { onBatchChange } = renderStep();

    const row = await screen.findByTestId("list-in-progress");
    await userEvent.click(within(row).getByRole("button", { name: "Open" }));

    expect(await screen.findByText("Hanna Girma")).toBeInTheDocument();
    expect(slices).toBe(1);
    expect(onBatchChange).toHaveBeenCalledWith(STAGING_ID);
  });

  it("shows no card while a list is open", async () => {
    setup([inProgress(REVIEWING_ID, "reviewing")], [batch(REVIEWING_ID)]);
    renderStep({ initialBatchId: REVIEWING_ID });

    expect(await screen.findByText("Hanna Girma")).toBeInTheDocument();
    expect(screen.queryByText("Lists in progress")).toBeNull();
  });

  it("opens the list it is given, as a reload of the page would", async () => {
    setup([], [batch(REVIEWING_ID)]);
    renderStep({ initialBatchId: REVIEWING_ID });

    expect(await screen.findByText("Hanna Girma")).toBeInTheDocument();
    expect(screen.queryByText("Stage for review")).toBeNull();
  });

  it("offers to carry on a given list that was still being read, and does", async () => {
    setup(
      [],
      [batch(STAGING_ID, { status: "staging", rows: [], summary: {} })],
    );
    renderStep({ initialBatchId: STAGING_ID });

    // Not a table of people nobody has matched, and not a commit that would be refused.
    expect(
      await screen.findByText(
        "This list was still being read. Carry on to finish it.",
      ),
    ).toBeInTheDocument();
    expect(screen.queryByText("Commit migration")).toBeNull();

    server.use(
      http.post("*/onboarding/migration/batches/:id/stage", () =>
        HttpResponse.json({
          public_id: STAGING_ID,
          status: "reviewing",
          staged: 1,
          remaining: 0,
        }),
      ),
      http.get("*/onboarding/migration/batches/:id", () =>
        HttpResponse.json(batch(STAGING_ID)),
      ),
    );
    await userEvent.click(screen.getByRole("button", { name: "Carry on" }));

    expect(await screen.findByText("Hanna Girma")).toBeInTheDocument();
  });

  it("lets go of a list that is gone, quietly, and shows the normal screen", async () => {
    setup([], []);
    const { onBatchChange } = renderStep({ initialBatchId: REVIEWING_ID });

    await waitFor(() => expect(onBatchChange).toHaveBeenCalledWith(null));
    expect(await screen.findByText("Stage for review")).toBeInTheDocument();
    expect(toast.error).not.toHaveBeenCalled();
  });

  it("tells the page when the reviewer starts over", async () => {
    setup([], [batch(REVIEWING_ID)]);
    const { onBatchChange } = renderStep({ initialBatchId: REVIEWING_ID });

    await screen.findByText("Hanna Girma");
    await userEvent.click(screen.getByRole("button", { name: "Start over" }));

    expect(onBatchChange).toHaveBeenLastCalledWith(null);
    expect(await screen.findByText("Stage for review")).toBeInTheDocument();
  });

  it("does not send invitations for a list that was added before the page was opened", async () => {
    // A reload lands on a list already added. The tick that says "invite them
    // as soon as they are added" belongs to the commit that was just made, not
    // to a page that merely shows the result.
    let invited = false;
    setup([], [batch(REVIEWING_ID, { status: "committed" })]);
    server.use(
      me(["employee.create", "employee.viewAny", "users.invite"]),
      http.get("*/onboarding/migration/batches/:id/terminals", () =>
        HttpResponse.json({
          visible: false,
          devices: [],
          without_number: 0,
          without_branch: 0,
          can_assign: false,
          held: [],
          held_more: 0,
        }),
      ),
      http.post("*/employees/logins", () => {
        invited = true;
        return HttpResponse.json({}, { status: 202 });
      }),
    );
    server.use(
      http.get("*/onboarding/migration/batches/:id/logins", () =>
        HttpResponse.json({
          without_login: [
            {
              employee_public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6E01",
              name: "Hanna Girma",
              email: "hanna@example.et",
              phone: null,
              channel: "email",
              invited: false,
            },
          ],
          hire_dates_defaulted: 0,
        }),
      ),
    );
    renderStep({ initialBatchId: REVIEWING_ID });

    // The people who cannot sign in are listed, and nobody is sent anything.
    expect(await screen.findByText("hanna@example.et")).toBeInTheDocument();
    await new Promise((resolve) => setTimeout(resolve, 400));
    expect(invited).toBe(false);
  });
});
