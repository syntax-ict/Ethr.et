import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import {
  SYNC_BATCH_CHARS,
  SYNC_BATCH_RECORDS,
  splitIntoBatches,
  toSyncPayload,
} from "@/lib/offline-batch";
import type { OfflineAttendanceRecord } from "@/lib/offline-queue";

/**
 * The phone sends its saved punches a few at a time. One request for the whole
 * queue stopped working once it passed 100 records or the host's 8 MB body
 * limit, and the queue then never drained.
 */
const sizeOf = (n: { size: number }) => n.size;
const items = (sizes: number[]) => sizes.map((size, i) => ({ id: i, size }));

describe("splitIntoBatches", () => {
  it("keeps the limits the server and host impose well clear", () => {
    expect(SYNC_BATCH_RECORDS).toBe(20);
    expect(SYNC_BATCH_CHARS).toBe(4_000_000);
  });

  it("splits by count, in order", () => {
    const batches = splitIntoBatches(items(Array(45).fill(10)), sizeOf);

    expect(batches.map((b) => b.length)).toEqual([20, 20, 5]);
    expect(batches.flat().map((i) => i.id)).toEqual([...Array(45).keys()]);
  });

  it("splits by size, in order", () => {
    // 1.5 MB each: two fit in 4 MB, a third does not.
    const batches = splitIntoBatches(
      items([1_500_000, 1_500_000, 1_500_000, 1_500_000, 1_500_000]),
      sizeOf,
    );

    expect(batches.map((b) => b.length)).toEqual([2, 2, 1]);
    expect(batches.flat().map((i) => i.id)).toEqual([0, 1, 2, 3, 4]);
  });

  it("puts one record larger than the budget in a batch of its own", () => {
    const batches = splitIntoBatches(items([100, 5_000_000, 100, 100]), sizeOf);

    expect(batches.map((b) => b.map((i) => i.id))).toEqual([[0], [1], [2, 3]]);
  });

  it("does not start a batch for an empty list", () => {
    expect(splitIntoBatches([], sizeOf)).toEqual([]);
  });

  it("sends the same record the server reads, selfie included", () => {
    const payload = toSyncPayload(
      {
        employee_public_id: "E1",
        type: "check_in",
        idempotency_key: "k",
        offline_token: "t",
        captured_at: "2026-11-20T05:00:00.000Z",
        photo: "data:image/jpeg;base64,AAAA",
        tenant: "acme",
        locale: "en",
        synced: 0,
      } as OfflineAttendanceRecord,
      "2026-11-20T05:01:00.000Z",
    );

    expect(payload).toMatchObject({
      timestamp: "2026-11-20T05:00:00.000Z",
      photo: "data:image/jpeg;base64,AAAA",
      client_time: "2026-11-20T05:01:00.000Z",
    });
    // Queue bookkeeping is not part of what is posted.
    expect(payload).not.toHaveProperty("tenant");
    expect(payload).not.toHaveProperty("synced");
  });
});

describe("the service worker's copy of the limits", () => {
  it("equals the module's, so the app and the worker cut the queue alike", () => {
    const source = readFileSync(
      resolve(__dirname, "../../public/sw.js"),
      "utf8",
    );
    const records = source.match(/const SYNC_BATCH_RECORDS = (\d+);/);
    const chars = source.match(/const SYNC_BATCH_CHARS = (\d+);/);

    expect(Number(records?.[1])).toBe(SYNC_BATCH_RECORDS);
    expect(Number(chars?.[1])).toBe(SYNC_BATCH_CHARS);
  });
});
