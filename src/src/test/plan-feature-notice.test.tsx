import { beforeEach, describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { PlanFeatureNotice } from "@/components/shared/plan-feature-notice";

/**
 * A feature can be off because the plan leaves it out or because ETHR
 * switched it off. Only the first is answered by a better plan, so only the
 * first offers one (docs/FEATURE-CONTROL-CENTER-DESIGN.md §5).
 */
function serveMe(reason: string) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "u1", name: "Admin", role: "hr_admin" },
        permissions: [],
        features: { payroll: { on: false, visible: false, reason } },
        tenant: null,
      }),
    ),
  );
}

function renderNotice() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <PlanFeatureNotice feature="payroll" />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  localStorage.setItem("locale", "en");
});

describe("plan feature notice", () => {
  it("offers plans when the plan leaves the feature out", async () => {
    serveMe("plan");
    renderNotice();

    expect(
      await screen.findByText(
        "This is not included in your organisation's plan.",
      ),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "See plans" })).toBeInTheDocument();
  });

  it("says ETHR switched it off for now, with no plans to buy", async () => {
    serveMe("platform_disabled");
    renderNotice();

    expect(
      await screen.findByText(
        "ETHR has switched this off for now. Your records are kept, and nothing needs doing on your side.",
      ),
    ).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "See plans" })).toBeNull();
  });

  it("points a revoked organisation to support, not to billing", async () => {
    serveMe("revoked");
    renderNotice();

    expect(
      await screen.findByText(
        "This is not available to your organisation right now. ETHR support can tell you why.",
      ),
    ).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "See plans" })).toBeNull();
  });
});
