import { describe, expect, it, vi } from "vitest";

// A phone that cannot load or run the face model (old WebView, no WASM, the
// model blocked): liveness says "unavailable", which the server treats as
// neutral, instead of failing the punch.
vi.mock("@mediapipe/tasks-vision", () => ({
  FilesetResolver: {
    forVisionTasks: vi.fn().mockRejectedValue(new Error("no wasm")),
  },
  FaceLandmarker: { createFromOptions: vi.fn() },
}));

describe("liveness", () => {
  it("reports unavailable when the model cannot load", async () => {
    const { checkLiveness } = await import("@/lib/attendance/liveness");
    const video = document.createElement("video");

    await expect(checkLiveness(video, "blink", 50)).resolves.toBe(
      "unavailable",
    );
  });

  it("asks for a blink or a turn", async () => {
    const { randomPrompt } = await import("@/lib/attendance/liveness");
    const seen = new Set(Array.from({ length: 40 }, () => randomPrompt()));

    expect([...seen].every((p) => p === "blink" || p === "turn")).toBe(true);
  });
});
