import { describe, expect, it } from "vitest";
import { isNoAnswer } from "@/lib/attendance/no-answer";

describe("isNoAnswer", () => {
  it("is true for a network error and a timeout, which have no response", () => {
    expect(isNoAnswer({ code: "ERR_NETWORK", message: "Network Error" })).toBe(
      true,
    );
    expect(isNoAnswer({ code: "ECONNABORTED", response: undefined })).toBe(
      true,
    );
    expect(isNoAnswer(new Error("Network Error"))).toBe(true);
  });

  it("is false for anything the server answered", () => {
    expect(isNoAnswer({ response: { status: 422, data: {} } })).toBe(false);
    expect(isNoAnswer({ response: { status: 500 } })).toBe(false);
  });

  it("is false for what is not an object", () => {
    expect(isNoAnswer(null)).toBe(false);
    expect(isNoAnswer(undefined)).toBe(false);
    expect(isNoAnswer("Network Error")).toBe(false);
    expect(isNoAnswer(42)).toBe(false);
  });
});
