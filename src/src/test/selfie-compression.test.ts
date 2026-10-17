import { describe, it, expect } from "vitest";
import {
  dataUrlBytes,
  fitSelfie,
  SELFIE_MAX_BYTES,
} from "@/lib/uploads/selfie";

/**
 * Attendance selfies are sent at most 1.5 MB (attendance photo policy). The
 * encoder is stood in for, since jsdom has no canvas: what matters is the
 * order the attempts are made in and which one is kept.
 */
function dataUrlOf(bytes: number): string {
  return "data:image/jpeg;base64," + "A".repeat(Math.ceil((bytes * 4) / 3));
}

describe("dataUrlBytes", () => {
  it("measures the decoded size", () => {
    expect(dataUrlBytes("data:image/jpeg;base64,QUJD")).toBe(3);
    expect(dataUrlBytes("data:image/jpeg;base64,QUI=")).toBe(2);
    expect(dataUrlBytes("data:image/jpeg;base64,QQ==")).toBe(1);
  });
});

describe("fitSelfie", () => {
  it("keeps the clearest encoding that fits", () => {
    const tried: Array<[number, number]> = [];
    const result = fitSelfie((edge, quality) => {
      tried.push([edge, quality]);
      // Only a lower quality at the full edge is small enough.
      return dataUrlOf(quality > 0.7 ? 2_000_000 : 1_000_000);
    });

    expect(dataUrlBytes(result)).toBeLessThanOrEqual(SELFIE_MAX_BYTES);
    expect(tried).toEqual([
      [1280, 0.85],
      [1280, 0.75],
      [1280, 0.65],
    ]);
  });

  it("steps the size down when no quality is enough", () => {
    const tried: number[] = [];
    fitSelfie((edge) => {
      tried.push(edge);
      return dataUrlOf(edge > 960 ? 3_000_000 : 1_000_000);
    });

    expect(tried.slice(-1)).toEqual([960]);
  });

  it("returns the smallest attempt when nothing fits", () => {
    const result = fitSelfie((edge, quality) =>
      dataUrlOf(2_000_000 + edge * 10 + quality * 100),
    );

    expect(dataUrlBytes(result)).toBeGreaterThan(SELFIE_MAX_BYTES);
    expect(result).toBe(dataUrlOf(2_000_000 + 720 * 10 + 0.55 * 100));
  });
});
