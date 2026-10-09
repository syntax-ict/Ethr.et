import { describe, it, expect, afterEach } from "vitest";
import { render, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import {
  TenantBrandingProvider,
  brandProperties,
} from "@/features/branding/TenantBrandingProvider";
import { contrastRatio, readableInkOn, tint } from "@/lib/utils/color";

/**
 * Tenant branding set `--primary` to an HSL triplet. globals.css builds
 * `--color-primary` from `--interactive-primary`, not `--primary`, and its
 * tokens are hex — so a tenant's saved colours changed nothing on screen.
 */
describe("colour helpers", () => {
  it("picks dark ink on a light brand colour and white on a dark one", () => {
    expect(readableInkOn("#fde047")).toBe("#0f172a"); // yellow
    expect(readableInkOn("#0f4c75")).toBe("#ffffff"); // the default navy
  });

  it("measures WCAG contrast", () => {
    expect(contrastRatio("#ffffff", "#000000")).toBeCloseTo(21, 0);
    expect(contrastRatio("#ffffff", "#ffffff")).toBeCloseTo(1, 5);
  });

  it("tints toward white", () => {
    expect(tint("#000000", 0.5)).toBe("#808080");
    expect(tint("not-a-colour", 0.5)).toBeNull();
  });
});

describe("brandProperties", () => {
  it("drives the tokens the theme actually reads", () => {
    const props = brandProperties({
      primary_color: "#7c3aed",
      accent_color: "#fde047",
    });

    expect(props["--interactive-primary"]).toBe("#7c3aed");
    expect(props["--interactive-focus"]).toBe("#7c3aed");
    expect(props["--color-primary-foreground"]).toBe("#ffffff");
    expect(props["--brand-accent"]).toBe("#fde047");
    expect(props["--brand-accent-foreground"]).toBe("#0f172a");
    expect(props).not.toHaveProperty("--primary");
  });

  it("ignores a value that is not a hex colour", () => {
    expect(
      brandProperties({ primary_color: "red; background: url(x)" }),
    ).toEqual({});
  });
});

describe("<TenantBrandingProvider>", () => {
  afterEach(() => {
    document.documentElement.removeAttribute("style");
  });

  it("applies the tenant's primary colour where buttons read it", async () => {
    server.use(
      http.get("*/api/v1/auth/me", () =>
        HttpResponse.json({
          user: { public_id: "U1", role: "tenant_admin" },
          tenant: {
            public_id: "T1",
            name: "Acme",
            theme: { primary_color: "#7c3aed" },
          },
          permissions: [],
        }),
      ),
    );
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });

    render(
      <QueryClientProvider client={qc}>
        <TenantBrandingProvider>
          <span>app</span>
        </TenantBrandingProvider>
      </QueryClientProvider>,
    );

    await waitFor(() =>
      expect(
        document.documentElement.style.getPropertyValue(
          "--interactive-primary",
        ),
      ).toBe("#7c3aed"),
    );
    expect(document.documentElement.style.getPropertyValue("--primary")).toBe(
      "",
    );
  });
});
