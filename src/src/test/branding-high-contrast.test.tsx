import { afterEach, describe, expect, it, vi } from "vitest";
import { render, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import {
  TenantBrandingProvider,
  TenantLogoBadge,
} from "@/features/branding/TenantBrandingProvider";

const theme = { resolvedTheme: "high-contrast" as string };
vi.mock("next-themes", () => ({
  useTheme: () => theme,
}));

/**
 * Organisation-landing follow-up G4. Brand colours are inline styles on
 * <html>, and an inline custom property beats the `.high-contrast` class's,
 * so an organisation's colours overrode a person's accessibility choice. The
 * choice wins.
 */
function renderWithBrand() {
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
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <TenantBrandingProvider>
        <span>app</span>
      </TenantBrandingProvider>
    </QueryClientProvider>,
  );
}

function brandPrimary() {
  return document.documentElement.style.getPropertyValue(
    "--interactive-primary",
  );
}

afterEach(() => {
  document.documentElement.removeAttribute("style");
  theme.resolvedTheme = "high-contrast";
});

describe("brand colours and high contrast", () => {
  it("are not applied under high contrast", async () => {
    renderWithBrand();

    // Give the tenant query time to land; the colour must still be absent.
    await new Promise((resolve) => setTimeout(resolve, 100));
    expect(brandPrimary()).toBe("");
  });

  it("are applied in the ordinary themes", async () => {
    theme.resolvedTheme = "light";
    renderWithBrand();

    await waitFor(() => expect(brandPrimary()).toBe("#7c3aed"));
  });
});

/**
 * The app shell's logo badge (2026-10-15): the sidebar's surface is dark in
 * both themes, so it shows the dark-background logo whenever one is
 * uploaded; everywhere else that logo is for dark mode only.
 */
describe("<TenantLogoBadge>", () => {
  afterEach(() => {
    theme.resolvedTheme = "light";
  });

  function renderBadge(surface?: "dark") {
    server.use(
      http.get("*/api/v1/auth/me", () =>
        HttpResponse.json({
          user: { public_id: "U1", role: "tenant_admin" },
          tenant: {
            public_id: "T1",
            name: "Acme",
            logo_path: "/api/v1/organisation-logos/T1/l0g0",
            logo_dark_url: "/api/v1/organisation-assets/T1/logo-dark/d4rk",
            theme: null,
          },
          permissions: [],
        }),
      ),
    );
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    return render(
      <QueryClientProvider client={qc}>
        <TenantLogoBadge surface={surface} />
      </QueryClientProvider>,
    );
  }

  it("shows the dark logo on the sidebar in light mode, and the light logo elsewhere", async () => {
    const sidebar = renderBadge("dark");
    await waitFor(() =>
      expect(sidebar.container.querySelector("img")).toHaveAttribute(
        "src",
        "/api/v1/organisation-assets/T1/logo-dark/d4rk",
      ),
    );
    sidebar.unmount();

    const header = renderBadge();
    await waitFor(() =>
      expect(header.container.querySelector("img")).toHaveAttribute(
        "src",
        "/api/v1/organisation-logos/T1/l0g0",
      ),
    );
    header.unmount();

    theme.resolvedTheme = "dark";
    const headerDark = renderBadge();
    await waitFor(() =>
      expect(headerDark.container.querySelector("img")).toHaveAttribute(
        "src",
        "/api/v1/organisation-assets/T1/logo-dark/d4rk",
      ),
    );
  });
});
