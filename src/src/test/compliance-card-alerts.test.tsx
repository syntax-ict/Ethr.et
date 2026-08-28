import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { ComplianceCard } from "@/features/dashboard/components/compliance-card";

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    can: { viewExecutiveDashboard: true },
    role: "hr_admin",
  }),
}));

const COMPLIANCE = {
  expiring_documents: { count: 0, items: [] },
  probation_overdue: { count: 0, items: [] },
  unused_leave: { count: 0, items: [], applicable: false },
};

function renderCard() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <ComplianceCard />
    </QueryClientProvider>,
  );
}

describe("<ComplianceCard> triggered alerts", () => {
  it("renders no alert banner when nothing is triggered", async () => {
    server.use(
      http.get("*/dashboard/executive/compliance", () =>
        HttpResponse.json(COMPLIANCE),
      ),
      http.get("*/dashboard/alert-thresholds/triggered", () =>
        HttpResponse.json({ alerts: [] }),
      ),
    );
    renderCard();

    expect(await screen.findByText("Compliance")).toBeInTheDocument();
    expect(screen.queryByText(/Turnover rate/)).not.toBeInTheDocument();
  });

  it("shows a breached threshold as an alert banner", async () => {
    server.use(
      http.get("*/dashboard/executive/compliance", () =>
        HttpResponse.json(COMPLIANCE),
      ),
      http.get("*/dashboard/alert-thresholds/triggered", () =>
        HttpResponse.json({
          alerts: [
            {
              public_id: "01HZALERT0000000000000001",
              metric: "turnover_rate",
              operator: "gt",
              threshold_value: 5,
              current_value: 12,
              severity: "critical",
            },
          ],
        }),
      ),
    );
    renderCard();

    expect(await screen.findByText(/Turnover rate/)).toBeInTheDocument();
    expect(screen.getByText("12")).toBeInTheDocument();
    expect(screen.getByText("critical")).toBeInTheDocument();
  });
});
