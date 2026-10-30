import { describe, it, expect } from "vitest";
import { safeInternalPath } from "@/lib/utils/safe-path";

/**
 * Security review 2026-10-30: the SSO return checked only the second
 * character of `next`, and the URL parser drops tabs and newlines, so
 * `/\t/evil.com` (sent as `%09`) passed and navigated off the site.
 */
describe("safeInternalPath", () => {
  it.each([
    ["/dashboard", "/dashboard"],
    ["/employees?status=active#top", "/employees?status=active#top"],
    ["/leave/../payroll", "/payroll"],
  ])("keeps the same-origin path %s", (value, expected) => {
    expect(safeInternalPath(value, "/fallback")).toBe(expected);
  });

  it.each([
    ["a tab before the second slash", "/\t/evil.com"],
    ["a newline before the second slash", "/\n/evil.com"],
    ["a protocol-relative URL", "//evil.com"],
    ["a backslash", "/\\evil.com"],
    ["a backslash after a tab", "/\t\\evil.com"],
    ["a full URL", "https://evil.com/dashboard"],
    ["a javascript URL", "javascript:alert(1)"],
    ["an API path", "/api/v1/auth/me"],
    ["the API root", "/api"],
    ["an empty value", ""],
    ["no value", null],
  ])("refuses %s", (_label, value) => {
    expect(safeInternalPath(value, "/fallback")).toBe("/fallback");
    expect(safeInternalPath(value)).toBeUndefined();
  });
});
