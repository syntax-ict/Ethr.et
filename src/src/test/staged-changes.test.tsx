import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { MigrationStep } from "@/features/onboarding/v2/components/migration-step";
import { StagedChanges } from "@/features/onboarding/v2/components/staged-changes";
import { CommitStalled, commitMigration } from "@/features/onboarding/v2/api";
import type {
  MigrationBatch,
  StagedChange,
  StagingRow,
} from "@/features/onboarding/v2/types";

/**
 * A staged row that matched an employee is never a second employee, so what
 * it says about them goes onto their record: blanks are filled, and a
 * difference waits for the reviewer (One employee identity, PR 5). And a long
 * list is added a slice at a time, the request sent again until it is done.
 */

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const change = (over: Partial<StagedChange>): StagedChange => ({
  field: "phone",
  kind: "conflict",
  current: "+251911000001",
  incoming: "+251922000002",
  masked: false,
  accepted: false,
  taken: false,
  ...over,
});

const row = (over: Partial<StagingRow> = {}): StagingRow => ({
  public_id: "01HZROW00000000000000001",
  display_name: "Abebe Kebede",
  external_identifier: "E-1",
  match_outcome: "matched",
  match_confidence: 0.9,
  candidates: [],
  resolved_employee_public_id: "01HZEMP0000000000000001",
  action: "merge",
  changes: [change({})],
  can_apply_changes: true,
  scheduled_action: false,
  processed_at: null,
  ...over,
});

describe("<StagedChanges>", () => {
  it("says what fills a blank, and asks about a difference", () => {
    const onAccept = vi.fn();
    render(
      <StagedChanges
        row={row({
          changes: [
            change({
              field: "email",
              kind: "fill",
              current: null,
              incoming: "abebe@example.et",
            }),
            change({}),
          ],
        })}
        locked={false}
        onAccept={onAccept}
      />,
    );

    expect(
      screen.getByText("Fills in Email: abebe@example.et"),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        "Phone: ETHR has +251911000001, this list has +251922000002",
      ),
    ).toBeInTheDocument();

    // Only the difference is a choice.
    const boxes = screen.getAllByRole("checkbox");
    expect(boxes).toHaveLength(1);

    fireEvent.click(boxes[0]);
    expect(onAccept).toHaveBeenCalledWith(["phone"]);
  });

  it("keeps the other accepted fields when one is unticked", () => {
    const onAccept = vi.fn();
    render(
      <StagedChanges
        row={row({
          changes: [
            change({ accepted: true }),
            change({
              field: "name",
              current: "Abebe K",
              incoming: "Abebe Kebede",
              accepted: true,
            }),
          ],
        })}
        locked={false}
        onAccept={onAccept}
      />,
    );

    fireEvent.click(screen.getAllByRole("checkbox")[0]);
    expect(onAccept).toHaveBeenCalledWith(["name"]);
  });

  it("names a salary without showing either figure", () => {
    render(
      <StagedChanges
        row={row({
          changes: [
            change({
              field: "salary_cents",
              current: null,
              incoming: null,
              masked: true,
            }),
          ],
        })}
        locked={false}
        onAccept={() => {}}
      />,
    );

    expect(
      screen.getByText("Salary is different in this list"),
    ).toBeInTheDocument();
  });

  it("offers no choice over a number someone else holds", () => {
    render(
      <StagedChanges
        row={row({
          changes: [
            change({
              field: "badge_number",
              kind: "fill",
              current: null,
              incoming: "500",
              taken: true,
            }),
          ],
        })}
        locked={false}
        onAccept={() => {}}
      />,
    );

    expect(
      screen.getByText(
        "Badge number: 500 belongs to someone else and is left as it is",
      ),
    ).toBeInTheDocument();
    expect(screen.queryByRole("checkbox")).toBeNull();
  });

  it("tells someone who may not edit the employee, and takes no answer from them", () => {
    render(
      <StagedChanges
        row={row({
          can_apply_changes: false,
          changes: [change({ current: null })],
        })}
        locked={false}
        onAccept={() => {}}
      />,
    );

    expect(
      screen.getByText(
        "You cannot edit this person, so their record is left as it is.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Phone: this list has +251922000002"),
    ).toBeInTheDocument();
    expect(screen.getByRole("checkbox")).toBeDisabled();
  });

  it("says a scheduled change is why the record is left alone", () => {
    render(
      <StagedChanges
        row={row({ scheduled_action: true })}
        locked={false}
        onAccept={() => {}}
      />,
    );

    expect(screen.getByText(/scheduled for a later date/)).toBeInTheDocument();
    expect(screen.getByRole("checkbox")).toBeDisabled();
  });

  it("shows nothing for a row that is not being merged", () => {
    const { container } = render(
      <StagedChanges
        row={row({ action: "skip" })}
        locked={false}
        onAccept={() => {}}
      />,
    );

    expect(container).toBeEmptyDOMElement();
  });
});

describe("commitMigration", () => {
  const result = (status: string, remaining: number, created: number) => ({
    message: "ok",
    totals: { created, merged: 0, updated: 0, skipped: 0, deferred: 0 },
    status,
    remaining,
    without_login: [],
    hire_dates_defaulted: 0,
  });

  it("sends the commit again until the list is done", async () => {
    const answers = [
      result("committing", 3, 2),
      result("committing", 1, 4),
      result("committed", 0, 5),
    ];
    let calls = 0;
    server.use(
      http.post("*/onboarding/migration/batches/:id/commit", () =>
        HttpResponse.json(answers[calls++]),
      ),
    );

    const seen: number[] = [];
    const done = await commitMigration("01HZBATCH", (r) =>
      seen.push(r.remaining),
    );

    expect(calls).toBe(3);
    expect(seen).toEqual([3, 1]);
    expect(done.status).toBe("committed");
    expect(done.totals.created).toBe(5);
  });

  it("stops when the list is not getting shorter", async () => {
    let calls = 0;
    server.use(
      http.post("*/onboarding/migration/batches/:id/commit", () => {
        calls++;
        return HttpResponse.json(result("committing", 3, 2));
      }),
    );

    await expect(commitMigration("01HZBATCH")).rejects.toBeInstanceOf(
      CommitStalled,
    );
    expect(calls).toBe(4);
  });
});

describe("<MigrationStep>, a matched row", () => {
  it("sends the fields the reviewer accepted with the row's own action", async () => {
    const batch: MigrationBatch = {
      public_id: "01HZBATCH0000000000000001",
      source_type: "csv",
      source_ref: null,
      status: "reviewing",
      totals: null,
      summary: { matched: 1 },
      rows: [row()],
    };

    let patched: Record<string, unknown> | null = null;
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(batch, { status: 201 }),
      ),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(batch),
      ),
      http.patch("*/onboarding/migration/rows/:rowId", async ({ request }) => {
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(batch.rows[0]);
      }),
    );

    const client = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
        mutations: { retry: false },
      },
    });
    const { container } = render(
      <QueryClientProvider client={client}>
        <MigrationStep />
      </QueryClientProvider>,
    );

    fireEvent.change(container.querySelector("textarea")!, {
      target: { value: "Abebe Kebede, , 0922000002" },
    });
    fireEvent.click(screen.getByText("Stage for review"));

    fireEvent.click(await screen.findByRole("checkbox"));

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ action: "merge", accept: ["phone"] });
  });
});
