import { afterEach, describe, expect, it, vi } from "vitest";
import { scrubLeakedFields } from "@/lib/auth/scrub-leaked-fields";

afterEach(() => {
  vi.restoreAllMocks();
  window.history.replaceState(null, "", "/");
});

describe("scrubLeakedFields", () => {
  it("removes credential-shaped fields and keeps the ones pages read", () => {
    window.history.replaceState(
      null,
      "",
      "/login/reset?token=t&email=a%40b.c&tenant=acme&org=acme&lang=am&error=x&mfa=1&next=%2Fdashboard" +
        "&password=p&password_confirmation=p&current_password=p&code=123456&identifier=i&employee_code=E1",
    );

    expect(scrubLeakedFields()).toBe(true);

    const params = new URLSearchParams(window.location.search);
    for (const name of [
      "password",
      "password_confirmation",
      "current_password",
      "code",
      "identifier",
      "employee_code",
    ]) {
      expect(params.has(name)).toBe(false);
    }
    expect(Object.fromEntries(params)).toEqual({
      token: "t",
      email: "a@b.c",
      tenant: "acme",
      org: "acme",
      lang: "am",
      error: "x",
      mfa: "1",
      next: "/dashboard",
    });
    expect(window.location.pathname).toBe("/login/reset");
  });

  it("keeps the hash and calls replaceState with null as the state", () => {
    window.history.replaceState(
      { router: "state" },
      "",
      "/login?password=p#top",
    );
    const spy = vi.spyOn(window.history, "replaceState");

    scrubLeakedFields();

    expect(spy).toHaveBeenCalledTimes(1);
    expect(spy.mock.calls[0][0]).toBeNull();
    expect(window.location.search).toBe("");
    expect(window.location.hash).toBe("#top");
  });

  it("does nothing when the address is clean", () => {
    window.history.replaceState(null, "", "/login?org=acme&lang=am");
    const spy = vi.spyOn(window.history, "replaceState");

    expect(scrubLeakedFields()).toBe(false);
    expect(spy).not.toHaveBeenCalled();
  });
});
