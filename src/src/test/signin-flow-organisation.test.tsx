import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, waitFor } from "@testing-library/react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import {
  queryOrganisation,
  signInHref,
  withOrganisation,
} from "@/lib/auth/entry-organisation";
import { LocaleRedirect } from "@/app/(root)/locale-redirect";

const replace = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, push: vi.fn(), back: vi.fn() }),
}));

/**
 * Organisation-landing follow-ups G2 and G5: the shared sign-in template at
 * `ethr.et/{slug}` keeps its organisation through the whole sign-in flow, and
 * an organisation's own address opens it.
 */

describe("the organisation a sign-in page is handed (G5)", () => {
  it("reads ?org= and the ?tenant= that reset e-mails carry", () => {
    expect(queryOrganisation("?org=acme")).toBe("acme");
    expect(queryOrganisation("token=x&email=a%40b.c&tenant=Acme")).toBe("acme");
  });

  it("accepts only an organisation slug, never a page of its own", () => {
    expect(queryOrganisation("?org=%3Cscript%3E")).toBeNull();
    expect(queryOrganisation("?org=login")).toBeNull();
    expect(queryOrganisation("")).toBeNull();
    expect(queryOrganisation(null)).toBeNull();
  });

  it("keeps the organisation on the flow's links, and back to its address", () => {
    expect(withOrganisation("/login/forgot", "acme")).toBe(
      "/login/forgot?org=acme",
    );
    expect(withOrganisation("/login/forgot", null)).toBe("/login/forgot");
    expect(signInHref("acme")).toBe("/acme");
    expect(signInHref(null)).toBe("/login");
  });
});

describe("an organisation's own address opens its sign-in page (G2)", () => {
  const originalLocation = window.location;

  function onHost(host: string) {
    Object.defineProperty(window, "location", {
      value: new URL(`https://${host}/`),
      writable: true,
      configurable: true,
    });
  }

  let tenantAnswer: unknown = null;

  beforeEach(() => {
    replace.mockReset();
    tenantAnswer = null;
    server.use(
      http.get("*/api/v1/auth/tenant-context", () =>
        HttpResponse.json({ tenant: tenantAnswer }),
      ),
    );
  });

  afterEach(() => {
    Object.defineProperty(window, "location", {
      value: originalLocation,
      writable: true,
      configurable: true,
    });
    vi.unstubAllEnvs();
  });

  it("goes to the sign-in page on the organisation's subdomain", async () => {
    vi.stubEnv("NEXT_PUBLIC_ROOT_DOMAIN", "ethr.et");
    onHost("syntax.ethr.et");

    render(<LocaleRedirect path="/" />);

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/login"));
  });

  it("goes to the sign-in page on a domain the server says is an organisation's", async () => {
    vi.stubEnv("NEXT_PUBLIC_ROOT_DOMAIN", "ethr.et");
    tenantAnswer = { name: "Acme", subdomain: "acme" };
    onHost("hr.acme.com");

    render(<LocaleRedirect path="/" />);

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/login"));
  });

  it("keeps ETHR's own site on the apex", async () => {
    vi.stubEnv("NEXT_PUBLIC_ROOT_DOMAIN", "ethr.et");
    onHost("www.ethr.et");

    render(<LocaleRedirect path="/" />);

    await waitFor(() => expect(replace).toHaveBeenCalled());
    expect(replace.mock.calls[0]![0]).toMatch(/^\/(en|am)$/);
  });

  it("keeps ETHR's site on a domain that names no organisation", async () => {
    vi.stubEnv("NEXT_PUBLIC_ROOT_DOMAIN", "ethr.et");
    onHost("unrelated.example");

    render(<LocaleRedirect path="/" />);

    await waitFor(() => expect(replace).toHaveBeenCalled());
    expect(replace.mock.calls[0]![0]).not.toBe("/login");
  });
});
