import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { MigrationStep } from "@/features/onboarding/v2/components/migration-step";
import {
  StagingInterrupted,
  StagingStalled,
  stageMigration,
  type StagingProgress,
} from "@/features/onboarding/v2/api";
import type { MigrationBatch } from "@/features/onboarding/v2/types";

/**
 * A long list is stored by the first request and matched a slice at a time:
 * the screen sends the request again until the list is ready, says how far
 * it has got, and when it stops it says so and can carry on.
 */

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const ID = "01HZBATCH0000000000000001";

const staging = (staged: number, remaining: number): StagingProgress => ({
  public_id: ID,
  status: "staging",
  staged,
  remaining,
});

const READY: MigrationBatch = {
  public_id: ID,
  source_type: "csv",
  source_ref: null,
  status: "reviewing",
  totals: null,
  summary: { new: 1 },
  rows: [
    {
      public_id: "01HZROW00000000000000001",
      display_name: "Matched Person",
      external_identifier: null,
      match_outcome: "new",
      match_confidence: 1,
      candidates: [],
      resolved_employee_public_id: null,
      action: "create",
      changes: [],
      can_apply_changes: false,
      scheduled_action: false,
      processed_at: null,
    },
  ],
};

const ready: StagingProgress = {
  public_id: ID,
  status: "reviewing",
  staged: 5,
  remaining: 0,
};

describe("stageMigration", () => {
  it("sends the request again until the list is ready, then reads it once", async () => {
    const answers = [staging(4, 1), ready];
    let slices = 0;
    let reads = 0;
    server.use(
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(staging(2, 3), { status: 201 }),
      ),
      http.post("*/onboarding/migration/batches/:id/stage", () =>
        HttpResponse.json(answers[slices++]),
      ),
      http.get("*/onboarding/migration/batches/:id", () => {
        reads++;
        return HttpResponse.json(READY);
      }),
    );

    const seen: number[] = [];
    const batch = await stageMigration({ rows: [{ name: "A" }] }, (p) =>
      seen.push(p.remaining),
    );

    expect(slices).toBe(2);
    expect(reads).toBe(1);
    expect(seen).toEqual([3, 1]);
    expect(batch.rows).toHaveLength(1);
  });

  it("does not ask again for a list that was short enough to answer whole", async () => {
    let slices = 0;
    server.use(
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(READY, { status: 201 }),
      ),
      http.post("*/onboarding/migration/batches/:id/stage", () => {
        slices++;
        return HttpResponse.json(ready);
      }),
    );

    const batch = await stageMigration({ rows: [{ name: "A" }] });

    expect(slices).toBe(0);
    expect(batch.public_id).toBe(ID);
  });

  it("stops when the list is not getting shorter, keeping the list's id", async () => {
    let slices = 0;
    server.use(
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(staging(2, 3), { status: 201 }),
      ),
      http.post("*/onboarding/migration/batches/:id/stage", () => {
        slices++;
        return HttpResponse.json(staging(2, 3));
      }),
    );

    const error = await stageMigration({ rows: [{ name: "A" }] }).catch(
      (e: unknown) => e,
    );

    expect(error).toBeInstanceOf(StagingInterrupted);
    expect((error as StagingInterrupted).cause).toBeInstanceOf(StagingStalled);
    expect((error as StagingInterrupted).batchId).toBe(ID);
    expect(slices).toBe(3);
  });

  it("throws a refusal of the list itself as it came", async () => {
    server.use(
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json({ errors: { grid: ["no"] } }, { status: 422 }),
      ),
    );

    const error = await stageMigration({ rows: [] }).catch((e: unknown) => e);

    expect(error).not.toBeInstanceOf(StagingInterrupted);
  });
});

describe("<MigrationStep> staging a long list", () => {
  function renderStep() {
    const client = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
        mutations: { retry: false },
      },
    });
    return render(
      <QueryClientProvider client={client}>
        <MigrationStep sources="list" />
      </QueryClientProvider>,
    );
  }

  it("says what happened when matching fails, and carries on from where it stopped", async () => {
    let failing = true;
    let posted = 0;
    server.use(
      http.post("*/onboarding/migration/read", () =>
        HttpResponse.json({
          people: 1,
          header: false,
          columns: [],
          ignored: [],
          calendar: "gregorian",
          calendar_from_header: false,
          without_hire_date: 0,
          problems: [],
          headers: [],
          header_likely: false,
          needs_name: false,
          source: null,
          suggested_source: null,
        }),
      ),
      http.post("*/onboarding/migration/rows", () => {
        posted++;
        return HttpResponse.json(staging(2, 3), { status: 201 });
      }),
      http.post("*/onboarding/migration/batches/:id/stage", () =>
        failing
          ? new HttpResponse("<html>Service Unavailable</html>", {
              status: 503,
            })
          : HttpResponse.json(ready),
      ),
      http.get("*/onboarding/migration/batches/:id", () =>
        HttpResponse.json(READY),
      ),
    );

    const { container } = renderStep();
    const textarea = container.querySelector("textarea") as HTMLTextAreaElement;
    fireEvent.change(textarea, { target: { value: "Matched Person" } });
    fireEvent.click(screen.getByText("Stage for review"));

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("matching the people stopped");

    failing = false;
    fireEvent.click(screen.getByText("Carry on"));

    await screen.findByText("Matched Person");
    await waitFor(() => expect(screen.queryByRole("alert")).toBeNull());
    // The list was not sent a second time.
    expect(posted).toBe(1);
  });
});
