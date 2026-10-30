import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { PausedFeaturesBanner } from "@/components/shared/paused-features-banner";

const permissions = vi.hoisted(() => ({ isHrAdmin: true }));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => permissions,
}));

/**
 * Owner decision D2: an organisation's admins are told, in the app, what the
 * platform has switched off for now. Nobody else gets a banner about it.
 */
function serveMe(paused: string[]) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "u1", name: "Admin", role: "hr_admin" },
        permissions: [],
        paused_features: paused,
        tenant: null,
      }),
    ),
  );
}

function renderBanner() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <PausedFeaturesBanner />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  localStorage.setItem("locale", "en");
  permissions.isHrAdmin = true;
});

describe("paused features banner", () => {
  it("names what the platform switched off, for an admin", async () => {
    serveMe(["payroll", "kiosk_check_in"]);
    renderBanner();

    expect(
      await screen.findByText(
        "ETHR has switched off Payroll, Kiosk check-in for now. Your records are kept, and nothing needs doing on your side.",
      ),
    ).toBeInTheDocument();
  });

  it("shows nothing when nothing is switched off", async () => {
    serveMe([]);
    const { container } = renderBanner();

    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(container).toBeEmptyDOMElement();
  });

  it("shows nothing to someone who is not an admin", async () => {
    permissions.isHrAdmin = false;
    serveMe(["payroll"]);
    const { container } = renderBanner();

    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(container).toBeEmptyDOMElement();
  });
});
