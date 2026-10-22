import { describe, it, expect, afterEach } from "vitest";
import { render, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import {
  DARK_SURFACE,
  TenantBrandingProvider,
  brandProperties,
} from "@/features/branding/TenantBrandingProvider";
import {
  aaCorrected,
  contrastRatio,
  readableInkOn,
  tint,
} from "@/lib/utils/color";

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

  // This grey reads at 4.3:1 under white and 4.2:1 under near-black: neither
  // ink reaches AA, so the colour itself moves, and only as far as it must.
  it("moves a colour only as far as AA contrast needs, and says so", () => {
    const grey = aaCorrected("#7a7a7a");
    expect(grey?.changed).toBe(true);
    expect(["#ffffff", "#0f172a"]).toContain(grey?.ink);
    expect(contrastRatio(grey!.ink, grey!.color)).toBeGreaterThanOrEqual(4.5);
    expect(contrastRatio(grey!.ink, grey!.color)).toBeLessThan(5.5);

    expect(aaCorrected("#0f4c75")).toEqual({
      color: "#0f4c75",
      ink: "#ffffff",
      changed: false,
    });
    expect(aaCorrected("nope")).toBeNull();
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

  it("hands the buttons the AA-corrected colour, not the raw one", () => {
    const props = brandProperties({ primary_color: "#7a7a7a" });
    expect(props["--interactive-primary"]).not.toBe("#7a7a7a");
    expect(
      contrastRatio(
        props["--color-primary-foreground"]!,
        props["--interactive-primary"]!,
      ),
    ).toBeGreaterThanOrEqual(4.5);
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

// A brand colour fine on white can vanish on the dark page (backlog 25).
describe("brand colours in dark mode", () => {
  it("keeps ETHR's colour when the brand cannot be seen on the dark page", () => {
    // Navy: AA under white ink, about 1.3:1 against the dark surface.
    const props = brandProperties({ primary_color: "#0a1f44" }, "dark");
    expect(props["--interactive-primary"]).toBeUndefined();
  });

  it("applies a brand colour that holds on the dark page", () => {
    const props = brandProperties({ primary_color: "#3b82f6" }, "dark");
    expect(props["--interactive-primary"]).toBeDefined();
    expect(
      contrastRatio(props["--interactive-primary"]!, DARK_SURFACE),
    ).toBeGreaterThanOrEqual(3);
  });

  it("still applies the navy brand in light mode", () => {
    const props = brandProperties({ primary_color: "#0a1f44" });
    expect(props["--interactive-primary"]).toBe("#0a1f44");
  });
});
