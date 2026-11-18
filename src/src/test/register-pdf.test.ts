import { describe, it, expect } from "vitest";
import { registerPdfRequest } from "@/features/attendance/register-pdf";

const none = { status: "all", source: "all", from: "", to: "" };

describe("registerPdfRequest", () => {
  it("asks for the attendance source as a PDF, with no filters when none are set", () => {
    const { body, unapplied } = registerPdfRequest(none);
    expect(body).toEqual({ source: "attendance", format: "pdf" });
    expect(unapplied).toEqual([]);
  });

  it("carries the dates and the status", () => {
    const { body, unapplied } = registerPdfRequest({
      ...none,
      status: "late",
      from: "2026-11-01",
      to: "2026-11-18",
    });
    expect(body.filters).toEqual({
      from: "2026-11-01",
      to: "2026-11-18",
      status: "late",
    });
    expect(unapplied).toEqual([]);
  });

  it("leaves out an unset end of the range", () => {
    const { body } = registerPdfRequest({ ...none, from: "2026-11-01" });
    expect(body.filters).toEqual({ from: "2026-11-01" });
  });

  it("names the source filter as not applied, and does not send it", () => {
    const { body, unapplied } = registerPdfRequest({
      ...none,
      source: "kiosk",
      status: "absent",
    });
    expect(unapplied).toEqual(["source"]);
    expect(JSON.stringify(body)).not.toContain("kiosk");
    expect(body.filters).toEqual({ status: "absent" });
  });
});
