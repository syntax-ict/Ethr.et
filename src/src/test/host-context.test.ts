import { describe, expect, it } from "vitest";

import { hostContext, tenantFromHost } from "@/lib/auth/host-context";

/**
 * The hostname model, from the browser's side.
 *
 * These mirror the backend assertions in
 * api/tests/Feature/Security/HostnameTenancyTest.php. If the two ever disagree,
 * the user gets an application that believes it is on a tenant host while the
 * API believes otherwise — which renders as a working UI with no data in it.
 */
describe("hostContext", () => {
  const ROOT = "ethr.et";

  it("classifies the apex as public, not as a tenant named 'ethr'", () => {
    // Counting labels gets this wrong — 'ethr.et' is two labels, so is
    // 'acme.com'. The backend had exactly this bug: the apex resolved to a
    // tenant named "ethr" and every request to the public site answered 404.
    expect(hostContext("ethr.et", ROOT)).toBe("public");
  });

  it("classifies the admin host as platform, never as a tenant", () => {
    expect(hostContext("admin.ethr.et", ROOT)).toBe("platform");
    expect(tenantFromHost("admin.ethr.et", ROOT)).toBeNull();
  });

  it("treats other reserved labels as the apex, not as tenants", () => {
    // `Tenant::RESERVED_SUBDOMAINS` — the API resolves no tenant on any of
    // these, so the browser must not believe it is on a tenant host either.
    // `www.ethr.et` is the one a real user reaches by typing: it used to render
    // "Sign in to www" with the organisation field hidden, leaving no way to
    // say which organisation you belong to.
    expect(hostContext("www.ethr.et", ROOT)).toBe("public");
    expect(hostContext("api.ethr.et", ROOT)).toBe("public");
    expect(hostContext("platform.ethr.et", ROOT)).toBe("public");
    expect(tenantFromHost("www.ethr.et", ROOT)).toBeNull();
  });

  it("classifies a single label in front of the apex as a tenant", () => {
    expect(hostContext("habru.ethr.et", ROOT)).toBe("tenant");
    expect(tenantFromHost("habru.ethr.et", ROOT)).toBe("habru");
    expect(tenantFromHost("woldia.ethr.et", ROOT)).toBe("woldia");
  });

  it("ignores the port", () => {
    expect(hostContext("admin.ethr.et:443", ROOT)).toBe("platform");
    expect(tenantFromHost("habru.ethr.et:3000", ROOT)).toBe("habru");
  });

  it("is case-insensitive, as hostnames are", () => {
    expect(hostContext("Admin.ETHR.et", ROOT)).toBe("platform");
    expect(tenantFromHost("HABRU.ethr.et", ROOT)).toBe("habru");
  });

  it("refuses a nested label — not routed, not covered by the wildcard cert", () => {
    expect(hostContext("a.b.ethr.et", ROOT)).toBe("unknown");
    expect(tenantFromHost("a.b.ethr.et", ROOT)).toBeNull();
  });

  it("refuses a lookalike domain that merely ends in the same letters", () => {
    // `notethr.et` ends with "ethr.et" as a *string* but is a different domain.
    // The dot-prefixed suffix check is what stops that being read as a tenant.
    expect(hostContext("notethr.et", ROOT)).toBe("unknown");
    expect(hostContext("evil-ethr.et", ROOT)).toBe("unknown");
  });

  it("stays inert without a root domain, so localhost development still works", () => {
    expect(hostContext("localhost:3000", null)).toBe("unknown");
    expect(hostContext("demo.localhost:3000", "")).toBe("unknown");
    expect(tenantFromHost("habru.ethr.et", null)).toBeNull();
  });

  it("tolerates a root domain written with stray dots", () => {
    expect(hostContext("habru.ethr.et", ".ethr.et.")).toBe("tenant");
  });
});
