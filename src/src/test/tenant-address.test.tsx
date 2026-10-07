import { afterEach, describe, expect, it, vi } from "vitest";
import { render } from "@testing-library/react";
import {
  tenantAddress,
  tenantAddressAffixes,
  type TenantAddressConfig,
} from "@/lib/tenant-address";

/**
 * Production builds in single-host mode — no NEXT_PUBLIC_ROOT_DOMAIN — because
 * the host serves no wildcard subdomains (M3). There `acme.ethr.et` does not
 * answer, and every place the UI printed it sent people to a Plesk login.
 * Subdomain mode must keep working for the day M3 passes.
 */
const SINGLE_HOST: TenantAddressConfig = { rootDomain: null, siteHost: "ethr.et" };
const SUBDOMAIN: TenantAddressConfig = { rootDomain: "ethr.et", siteHost: "ethr.et" };

describe("tenantAddress", () => {
  it("is the path form in single-host mode", () => {
    expect(tenantAddress("acme", null, SINGLE_HOST)).toBe("ethr.et/acme");
  });

  it("is the subdomain form when a root domain is configured", () => {
    expect(tenantAddress("acme", null, SUBDOMAIN)).toBe("acme.ethr.et");
  });

  it("is the custom domain in either mode when one is assigned", () => {
    expect(tenantAddress("acme", "hr.acme.com", SINGLE_HOST)).toBe("hr.acme.com");
    expect(tenantAddress("acme", "hr.acme.com", SUBDOMAIN)).toBe("hr.acme.com");
  });

  it("follows the build's own environment by default", () => {
    // The test environment sets no NEXT_PUBLIC_ROOT_DOMAIN, as production does.
    expect(tenantAddress("acme")).toBe("ethr.et/acme");
  });
});

describe("tenantAddressAffixes", () => {
  it("puts the host before the slug in single-host mode", () => {
    expect(tenantAddressAffixes(SINGLE_HOST)).toEqual({
      prefix: "ethr.et/",
      suffix: null,
    });
  });

  it("puts the root domain after the slug in subdomain mode", () => {
    expect(tenantAddressAffixes(SUBDOMAIN)).toEqual({
      prefix: null,
      suffix: ".ethr.et",
    });
  });
});

// The component reads the env at module load, so each mode needs a fresh import.
async function renderAffixes(rootDomain: string) {
  vi.stubEnv("NEXT_PUBLIC_ROOT_DOMAIN", rootDomain);
  vi.resetModules();
  const { TenantAddressAffix } = await import(
    "@/components/shared/tenant-address-affix"
  );
  return render(
    <div data-testid="field">
      <TenantAddressAffix side="prefix" bordered />
      <input defaultValue="acme" />
      <TenantAddressAffix side="suffix" bordered />
    </div>,
  );
}

describe("TenantAddressAffix", () => {
  afterEach(() => {
    vi.unstubAllEnvs();
    vi.resetModules();
  });

  it("renders only the ethr.et/ prefix in single-host mode", async () => {
    const { getByTestId } = await renderAffixes("");
    const field = getByTestId("field");
    expect(field.firstElementChild).toHaveTextContent("ethr.et/");
    expect(field.lastElementChild?.tagName).toBe("INPUT");
    expect(field).not.toHaveTextContent(".ethr.et");
  });

  it("renders only the .ethr.et suffix in subdomain mode", async () => {
    const { getByTestId } = await renderAffixes("ethr.et");
    const field = getByTestId("field");
    expect(field.firstElementChild?.tagName).toBe("INPUT");
    expect(field.lastElementChild).toHaveTextContent(".ethr.et");
    expect(field).not.toHaveTextContent("ethr.et/");
  });
});
