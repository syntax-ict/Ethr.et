import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { FRAME_GUARD_SCRIPT, STATIC_EXPORT_CSP } from "@/lib/static-page-guard";

/**
 * A-Z review W6-01 (2026-11-05). The host serves `/` and any direct `.html`
 * URL from disk, ahead of the `.htaccess` rules, so those pages arrive with
 * none of the security headers. The exported pages therefore carry the
 * policy themselves, and hide when framed, since `frame-ancestors` cannot be
 * set from a page.
 */
const HTACCESS = resolve(
  __dirname,
  "../../../docs/deployment/shared-hosting/.htaccess",
);

function htaccessCsp(): string {
  const match = readFileSync(HTACCESS, "utf8").match(
    /Header always set Content-Security-Policy "([^"]+)"/,
  );
  if (!match) throw new Error("no Content-Security-Policy in the template");
  return match[1];
}

function runGuard(framed: boolean) {
  const style: { display?: string } = {};
  const self = {};
  const window = { self, top: framed ? {} : self };
  const document = { documentElement: { style } };
  new Function("window", "document", FRAME_GUARD_SCRIPT)(window, document);
  return style;
}

describe("the policy an exported page carries itself", () => {
  it("is the .htaccess policy without frame-ancestors, which a page cannot set", () => {
    const expected = htaccessCsp()
      .split(";")
      .map((d) => d.trim())
      .filter((d) => d !== "" && !d.startsWith("frame-ancestors"))
      .join("; ");

    expect(STATIC_EXPORT_CSP).toBe(expected);
  });
});

describe("the frame guard", () => {
  it("hides a page shown inside a frame", () => {
    expect(runGuard(true).display).toBe("none");
  });

  it("leaves a page alone when it is the top window", () => {
    expect(runGuard(false).display).toBeUndefined();
  });
});
