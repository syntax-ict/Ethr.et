import { afterEach, describe, expect, it, vi } from "vitest";
import { render, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { TenantBrandingProvider } from "@/features/branding/TenantBrandingProvider";

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
