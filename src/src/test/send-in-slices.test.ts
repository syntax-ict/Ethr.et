import { describe, expect, it, vi } from "vitest";
import {
  addTotals,
  NO_TOTALS,
  sendInSlices,
  SIGN_IN_SLICE,
} from "@/features/employees/send-in-slices";

const ids = (n: number) => Array.from({ length: n }, (_, i) => `id-${i}`);

describe("sendInSlices", () => {
  it("sends 450 people as 200, 200 and 50, one after another", async () => {
    const sizes: number[] = [];
    let inFlight = 0;
    let overlapped = false;

    const run = await sendInSlices(ids(450), async (slice) => {
      inFlight += 1;
      overlapped ||= inFlight > 1;
      sizes.push(slice.length);
      await Promise.resolve();
      inFlight -= 1;
      return slice.length;
    });

    expect(SIGN_IN_SLICE).toBe(200);
    expect(sizes).toEqual([200, 200, 50]);
    expect(overlapped).toBe(false);
    expect(run).toEqual({
      results: [200, 200, 50],
      sent: 450,
      unsent: [],
      error: null,
    });
  });

  it("keeps every id in order and none twice", async () => {
    const seen: string[] = [];
    await sendInSlices(ids(450), async (slice) => {
      seen.push(...slice);
    });
    expect(seen).toEqual(ids(450));
  });

  it("stops at a failed slice and says what was sent and what is left", async () => {
    const boom = new Error("server down");
    const post = vi
      .fn<(slice: string[]) => Promise<number>>()
      .mockResolvedValueOnce(200)
      .mockRejectedValueOnce(boom);

    const run = await sendInSlices(ids(450), post);

    expect(post).toHaveBeenCalledTimes(2);
    expect(run.results).toEqual([200]);
    expect(run.sent).toBe(200);
    expect(run.error).toBe(boom);
    expect(run.unsent).toEqual(ids(450).slice(200));
  });

  it("sends nothing for an empty list", async () => {
    const post = vi.fn();
    const run = await sendInSlices([], post);
    expect(post).not.toHaveBeenCalled();
    expect(run).toEqual({ results: [], sent: 0, unsent: [], error: null });
  });
});

describe("addTotals", () => {
  it("adds the answers of every slice to what was already counted", () => {
    const total = addTotals({ ...NO_TOTALS, created: 1 }, [
      { created: 3, active: 1, in_person: 2, email_in_use: 0, queued: 1 },
      { created: 2, active: 0, in_person: 1, email_in_use: 4, queued: 2 },
    ]);
    expect(total).toEqual({
      created: 6,
      active: 1,
      in_person: 3,
      email_in_use: 4,
      queued: 3,
    });
  });
});
