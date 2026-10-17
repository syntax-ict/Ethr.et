import { describe, it, expect } from "vitest";
import {
  overServerLimit,
  shrinkImage,
  sizeLabel,
} from "@/lib/uploads/upload-limit";

/**
 * The server's real upload limit (production measured 2026-10-17 at PHP's
 * stock 2 MB) and the helpers that keep forms under it.
 */
function fileOf(bytes: number, type = "application/pdf"): File {
  const file = new File(["x"], "f", { type });
  Object.defineProperty(file, "size", { value: bytes });
  return file;
}

describe("sizeLabel", () => {
  it("says sizes the way people read them", () => {
    expect(sizeLabel(2 * 1024 * 1024)).toBe("2 MB");
    expect(sizeLabel(2.5 * 1024 * 1024)).toBe("2.5 MB");
    expect(sizeLabel(512 * 1024)).toBe("512 KB");
    expect(sizeLabel(10)).toBe("1 KB");
  });
});

describe("overServerLimit", () => {
  const MB = 1024 * 1024;

  it("names the server's limit for a file between it and the form's", () => {
    expect(overServerLimit(fileOf(3 * MB), 10 * MB, 2 * MB)).toBe(2 * MB);
  });

  it("leaves a file that fits alone", () => {
    expect(overServerLimit(fileOf(1 * MB), 10 * MB, 2 * MB)).toBeNull();
  });

  it("defers to the form's own message when the server allows more", () => {
    expect(overServerLimit(fileOf(12 * MB), 10 * MB, 20 * MB)).toBeNull();
  });

  it("says nothing until the limit is known", () => {
    expect(overServerLimit(fileOf(3 * MB), 10 * MB, null)).toBeNull();
  });
});

describe("shrinkImage", () => {
  it("returns the original file when the browser cannot decode it", async () => {
    // jsdom has no createImageBitmap, which stands in for any browser that
    // cannot shrink: the upload goes ahead unchanged.
    const photo = fileOf(4 * 1024 * 1024, "image/jpeg");
    await expect(
      shrinkImage(photo, { maxEdge: 1600, type: "image/jpeg" }),
    ).resolves.toBe(photo);
  });
});
