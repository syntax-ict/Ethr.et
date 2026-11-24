import { describe, it, expect, vi, beforeEach } from "vitest";
import { toast } from "sonner";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import {
  AcceptDifferences,
  UNTICKED_BY_DEFAULT,
} from "@/features/onboarding/v2/components/accept-differences";
import type {
  MigrationBatch,
  StagedChange,
  StagingRow,
} from "@/features/onboarding/v2/types";

/**
 * Accepting the same fields for many rows at once: what the dialog offers
 * (identity and hidden fields unticked), the counts it shows from the rows
 * the page holds, and the request sent again until the server says it is done.
 */

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const BATCH_ID = "01JBQ8Z6ZK3M9V2X7Y4W5T6U01";
const URL = `*/onboarding/migration/batches/${BATCH_ID}/accept-differences`;

const change = (
  field: string,
  over: Partial<StagedChange> = {},
): StagedChange => ({
  field,
  kind: "conflict",
  current: "old",
  incoming: "new",
  masked: false,
  accepted: false,
  taken: false,
  ...over,
});

let n = 0;
const row = (over: Partial<StagingRow> = {}): StagingRow => {
  n += 1;
  return {
    public_id: `01HZROW${String(n).padStart(17, "0")}`,
    display_name: `Person ${n}`,
    external_identifier: null,
    match_outcome: "matched",
    match_confidence: 0.9,
    candidates: [],
    resolved_employee_public_id: `01HZEMP${String(n).padStart(17, "0")}`,
    action: "merge",
    changes: [change("phone"), change("email"), change("name")],
    can_apply_changes: true,
    scheduled_action: false,
    processed_at: null,
    ...over,
  };
};

type Batch = Pick<MigrationBatch, "status" | "mode" | "rows">;

const batch = (rows: StagingRow[], over: Partial<Batch> = {}): Batch => ({
  status: "reviewing",
  mode: "add",
  rows,
  ...over,
});

function renderIt(b: Batch, onDone = vi.fn()) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  render(
    <QueryClientProvider client={client}>
      <AcceptDifferences batchId={BATCH_ID} batch={b} onDone={onDone} />
    </QueryClientProvider>,
  );
  return onDone;
}

const BUTTON = { name: "Accept differences" };

beforeEach(() => {
  vi.mocked(toast.success).mockClear();
  vi.mocked(toast.error).mockClear();
});

describe("the button", () => {
  it("is offered while the list is in review and a row to merge has a difference", () => {
    renderIt(batch([row()]));
    expect(screen.getByRole("button", BUTTON)).toBeInTheDocument();
  });

  it("is absent once the list is being added or added", () => {
    renderIt(batch([row()], { status: "committing" }));
    expect(screen.queryByRole("button", BUTTON)).toBeNull();
  });

  it("is absent when no merge row has a difference to take", () => {
    renderIt(
      batch([
        row({ changes: [change("phone", { kind: "fill", current: null })] }),
        row({ action: "skip" }),
        row({ changes: [change("phone", { accepted: true })] }),
        row({ changes: [change("employee_code", { taken: true })] }),
        row({ changes: [change("position", { kind: "locked" })] }),
        row({ can_apply_changes: false }),
      ]),
    );
    expect(screen.queryByRole("button", BUTTON)).toBeNull();
  });
});

describe("the dialog", () => {
  it("counts the people each field would change, and starts identity and hidden fields unticked", async () => {
    const user = userEvent.setup();
    renderIt(
      batch([
        row({
          changes: [
            change("phone"),
            change("name"),
            change("tin", { masked: true, current: null, incoming: null }),
          ],
        }),
        row({ changes: [change("phone"), change("name")] }),
        row({ changes: [change("phone")] }),
      ]),
    );
    await user.click(screen.getByRole("button", BUTTON));

    const dialog = await screen.findByRole("dialog");
    const phone = within(dialog).getByRole("checkbox", { name: /Phone/ });
    const name = within(dialog).getByRole("checkbox", { name: /Name/ });

    expect(phone).toBeChecked();
    expect(name).not.toBeChecked();
    expect(within(dialog).getByText("3 people")).toBeInTheDocument();
    expect(within(dialog).getAllByText("2 people").length).toBeGreaterThan(0);
    expect(within(dialog).getByText("1 person")).toBeInTheDocument();
    expect(
      within(dialog).getAllByText(/would put one person's details on another/)
        .length,
    ).toBeGreaterThan(0);
  });

  it("leaves the whole identity and hidden list unticked", () => {
    expect(UNTICKED_BY_DEFAULT).toEqual([
      "name",
      "name_am",
      "employee_code",
      "national_id",
      "date_of_birth",
      "hire_date",
      "tin",
      "salary_cents",
    ]);
  });

  it("shows rows that may be someone else as a count left for the reviewer, and does not count their fields", async () => {
    const user = userEvent.setup();
    renderIt(
      batch([
        row({ changes: [change("phone")] }),
        row({
          changes: [change("phone")],
          warnings: [
            { code: "maybe_other_person", message: "May be someone else" },
          ],
        }),
      ]),
    );
    await user.click(screen.getByRole("button", BUTTON));

    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("1 person")).toBeInTheDocument();
    expect(
      within(dialog).getByText(/1 row may be someone else/),
    ).toBeInTheDocument();
  });

  it("sends only the ticked fields, sends again until done, and says what happened", async () => {
    const user = userEvent.setup();
    const bodies: { fields: string[]; cursor: number }[] = [];
    const slices = [
      {
        done: false,
        cursor: 50,
        rows: 50,
        by_field: { phone: 50 },
        out_of_reach: 1,
        pay_refused: 0,
        held: 0,
      },
      {
        done: false,
        cursor: 90,
        rows: 40,
        by_field: { phone: 40 },
        out_of_reach: 0,
        pay_refused: 2,
        held: 1,
      },
      {
        done: true,
        cursor: 120,
        rows: 10,
        by_field: { phone: 10 },
        out_of_reach: 2,
        pay_refused: 0,
        held: 0,
      },
    ];
    server.use(
      http.post(URL, async ({ request }) => {
        bodies.push(
          (await request.json()) as { fields: string[]; cursor: number },
        );
        return HttpResponse.json(slices[bodies.length - 1]);
      }),
    );
    const onDone = renderIt(batch([row(), row()]));
    await user.click(screen.getByRole("button", BUTTON));

    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Accept" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalled());

    // Phone and email were ticked; the name was not.
    expect(bodies.map((b) => b.cursor)).toEqual([0, 50, 90]);
    expect(bodies[0].fields.sort()).toEqual(["email", "phone"]);
    expect(toast.success).toHaveBeenCalledWith(
      expect.stringContaining(
        "Accepted for 100 people. 3 you may not edit. 1 left for you.",
      ),
    );
    expect(toast.success).toHaveBeenCalledWith(
      expect.stringContaining("Salary and TIN were left for 2 people"),
    );
    expect(onDone).toHaveBeenCalled();
  });

  it("will not send with nothing ticked", async () => {
    const user = userEvent.setup();
    renderIt(batch([row({ changes: [change("name")] })]));
    await user.click(screen.getByRole("button", BUTTON));

    const dialog = await screen.findByRole("dialog");
    expect(
      within(dialog).getByRole("button", { name: "Accept" }),
    ).toBeDisabled();
  });

  it("says so when the server refuses, and keeps the dialog open", async () => {
    const user = userEvent.setup();
    server.use(
      http.post(URL, () =>
        HttpResponse.json({ message: "Nope" }, { status: 409 }),
      ),
    );
    renderIt(batch([row()]));
    await user.click(screen.getByRole("button", BUTTON));

    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Accept" }));

    await waitFor(() => expect(toast.error).toHaveBeenCalled());
    expect(screen.getByRole("dialog")).toBeInTheDocument();
  });
});
