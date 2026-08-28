import { describe, it, expect } from "vitest";
import { resizeSteps } from "@/features/shifts/rotations";

/**
 * The rotation form always submits a step for every offset in the cycle. The
 * API rejects an offset that falls outside `cycle_days` (it would be
 * unreachable) and rejects duplicates, so resizing has to produce a complete,
 * contiguous, deduplicated set rather than patching the previous one.
 */
describe("resizeSteps", () => {
  it("fills a fresh cycle with rest days", () => {
    expect(resizeSteps([], 3)).toEqual([
      { day_offset: 0, shift_id: null },
      { day_offset: 1, shift_id: null },
      { day_offset: 2, shift_id: null },
    ]);
  });

  it("keeps existing choices when the cycle grows", () => {
    const existing = [
      { day_offset: 0, shift_id: "shift-a" },
      { day_offset: 1, shift_id: "shift-b" },
    ];

    expect(resizeSteps(existing, 4)).toEqual([
      { day_offset: 0, shift_id: "shift-a" },
      { day_offset: 1, shift_id: "shift-b" },
      { day_offset: 2, shift_id: null },
      { day_offset: 3, shift_id: null },
    ]);
  });

  it("drops the unreachable tail when the cycle shrinks", () => {
    // Offsets 2 and 3 no longer exist in a 2-day cycle. Carrying them over
    // would make the API reject the whole rotation.
    const existing = [
      { day_offset: 0, shift_id: "shift-a" },
      { day_offset: 1, shift_id: "shift-b" },
      { day_offset: 2, shift_id: "shift-c" },
      { day_offset: 3, shift_id: "shift-d" },
    ];

    expect(resizeSteps(existing, 2)).toEqual([
      { day_offset: 0, shift_id: "shift-a" },
      { day_offset: 1, shift_id: "shift-b" },
    ]);
  });

  it("produces contiguous offsets even from a sparse input", () => {
    // A rotation loaded from the API need not define every offset — an
    // undefined one is a rest day server-side — but the form must still show
    // and submit every day.
    const sparse = [{ day_offset: 2, shift_id: "shift-c" }];

    expect(resizeSteps(sparse, 4)).toEqual([
      { day_offset: 0, shift_id: null },
      { day_offset: 1, shift_id: null },
      { day_offset: 2, shift_id: "shift-c" },
      { day_offset: 3, shift_id: null },
    ]);
  });

  it("returns nothing for a non-positive cycle instead of throwing", () => {
    // The cycle input is a number field a user can empty mid-edit.
    expect(resizeSteps([{ day_offset: 0, shift_id: "a" }], 0)).toEqual([]);
    expect(resizeSteps([], -3)).toEqual([]);
  });

  it("expresses a four-on-four-off cycle", () => {
    const fourOnFourOff = resizeSteps(
      [0, 1, 2, 3].map((day_offset) => ({ day_offset, shift_id: "day-shift" })),
      8,
    );

    expect(fourOnFourOff).toHaveLength(8);
    expect(fourOnFourOff.filter((s) => s.shift_id !== null)).toHaveLength(4);
    expect(fourOnFourOff.slice(4).every((s) => s.shift_id === null)).toBe(true);
  });
});
