import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { LandingContent } from "@/app/landing-content";
import { PricingContent } from "@/app/(marketing)/pricing/pricing-content";
import { FaqContent } from "@/app/(marketing)/faq/faq-content";

// FAQ is still a pure presentational client component: it depends only on the
// i18n layer (en is registered in the test setup) and next/link.
//
// Landing and Pricing are not. Pricing reads the plan catalog and Landing reads
// the published site metrics, so both need a QueryClient. Neither test stubs a
// request: the point is that the page renders correctly with no network at all,
// which is what a crawler gets and what an operator who has published nothing
// gets.
function withQuery(ui: React.ReactElement) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>,
  );
}

describe("Landing page", () => {
  it("renders every headline section (S06)", () => {
    withQuery(<LandingContent />);

    // Hero
    expect(
      screen.getByRole("heading", {
        name: "The Complete HR Platform for Ethiopian Organizations",
      }),
    ).toBeInTheDocument();

    // Feature highlights
    expect(screen.getByText("Everything You Need")).toBeInTheDocument();
    expect(screen.getByText("Attendance Tracking")).toBeInTheDocument();
    expect(screen.getByText("Payroll Management")).toBeInTheDocument();

    // Industry showcase
    expect(
      screen.getByText("Built for Ethiopian Industries"),
    ).toBeInTheDocument();
    expect(screen.getByText("Government")).toBeInTheDocument();
    expect(screen.getByText("Banking")).toBeInTheDocument();
    expect(screen.getByText("Manufacturing")).toBeInTheDocument();

    // CTA banner
    expect(
      screen.getByText("Start Your 6-Month Free Trial"),
    ).toBeInTheDocument();
  });

  it("points its primary CTAs at the registration flow", () => {
    withQuery(<LandingContent />);
    const trialLinks = screen
      .getAllByRole("link")
      .filter((a) => a.getAttribute("href") === "/register");
    expect(trialLinks.length).toBeGreaterThan(0);
  });

  it("states no figure it cannot substantiate", () => {
    withQuery(<LandingContent />);

    // The page carried "500+ organizations", "50,000+ employees", "1M+ payrolls
    // processed" and "99.9% uptime", all written into the component. ethr.et
    // serves nothing yet, so none of them were true — and "99.9%" reads as an
    // SLA the terms page says does not exist.
    //
    // They are columns now, and empty. With nothing published the band is not
    // rendered at all, which is what this pins: the failure mode to guard
    // against is someone reintroducing a default.
    expect(screen.queryByText("99.9%")).not.toBeInTheDocument();
    expect(screen.queryByText("500+")).not.toBeInTheDocument();
    expect(screen.queryByText("50,000+")).not.toBeInTheDocument();
    expect(screen.queryByText("1M+")).not.toBeInTheDocument();
    expect(screen.queryByText("Organizations")).not.toBeInTheDocument();

    // And the hero badge that counted them is gone with them.
    expect(screen.queryByText(/Now serving/i)).not.toBeInTheDocument();
  });
});

describe("Pricing page", () => {
  /**
   * The pricing page now reads the plan catalog from `GET /api/v1/plans`, so it
   * needs a QueryClient where it previously needed nothing. In the app that
   * comes from the root `Providers`; here it is supplied per test.
   *
   * No request is stubbed on purpose. The point of the build-time snapshot is
   * that the page renders real prices with no network at all — which is what a
   * crawler gets — so a test that had to mock the API would be testing the
   * wrong path.
   */
  function renderPricing() {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    return render(
      <QueryClientProvider client={queryClient}>
        <PricingContent />
      </QueryClientProvider>,
    );
  }

  it("renders every plan in the catalog, priced from the database (S06)", () => {
    renderPricing();

    expect(
      screen.getByRole("heading", { name: "Starter" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "Professional" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "Enterprise" }),
    ).toBeInTheDocument();

    // The catalog prices, not the literals the page used to carry. It advertised
    // "2,500" for Professional while `plans.price_cents` said 99900 — and
    // BillingService bills price_cents, so the advertised figure was never the
    // charged one. These assertions are what stop that drifting back.
    expect(screen.getByText("999")).toBeInTheDocument();
    expect(screen.getByText("2,999")).toBeInTheDocument();
    expect(screen.getByText("Free")).toBeInTheDocument();
  });

  it("renders the limits the product actually enforces", () => {
    renderPricing();

    // PlanLimitService enforces these. The old page promised "up to 50" for
    // Starter and "up to 200" for Professional — five and two times the real
    // ceilings, which a customer would discover on the day they hit one.
    expect(screen.getByText("Up to 10 employees")).toBeInTheDocument();
    expect(screen.getByText("Up to 100 employees")).toBeInTheDocument();
    expect(screen.getByText("Up to 20 devices")).toBeInTheDocument();

    // Starter allows exactly one branch. "Up to 1 branches" is the plural bug
    // that a count-interpolated string gets wrong on the very first card.
    expect(screen.getByText("1 branch")).toBeInTheDocument();

    // A null limit is "no ceiling" — PlanLimitService:53 returns null and every
    // caller honours it. The seeder used to express this as 999999, which the
    // page duly advertised as "Up to 999,999 employees".
    expect(screen.getByText("Unlimited employees")).toBeInTheDocument();
    expect(screen.queryByText(/999,999/)).not.toBeInTheDocument();
  });

  it("shows the selling points an admin wrote, from the catalog", () => {
    renderPricing();

    // marketing_features, edited at /admin/plans. Before the column existed
    // these bullets were literals in this component — including four ("priority
    // support", "SLA", "on-premise", "training") the product does not offer.
    expect(
      screen.getByText("Payroll with Ethiopian income tax and pension"),
    ).toBeInTheDocument();
    expect(
      screen.getByText("No employee, branch or device limit"),
    ).toBeInTheDocument();
  });

  it("never prints a raw capability key on a public page", () => {
    renderPricing();

    // `features` holds PlanFeature enum values — "api_access", not a sentence.
    // They are the fallback for a plan with no admin-written copy, and even
    // then they go through featureLabel. The dashboard renders them raw; a
    // public page must not.
    expect(screen.queryByText("api_access")).not.toBeInTheDocument();
    expect(screen.queryByText("employee_management")).not.toBeInTheDocument();
  });

  it("marks the popular plan from the catalog, not from a hardcoded slug", () => {
    renderPricing();

    // is_popular is a column now. The badge was previously pinned to
    // Professional in this component, so an admin promoting a different tier
    // would have had the highlight stay where a developer put it.
    expect(screen.getByText("Most popular")).toBeInTheDocument();
  });

  it("renders the FAQ accordion", () => {
    renderPricing();
    expect(
      screen.getByRole("heading", { name: "Frequently Asked Questions" }),
    ).toBeInTheDocument();
    expect(screen.getByText("How long is the free trial?")).toBeInTheDocument();
  });
});

describe("FAQ page", () => {
  it("groups questions into categories and answers them (S06)", () => {
    render(<FaqContent />);

    // Hero
    expect(
      screen.getByRole("heading", { name: "Frequently Asked Questions" }),
    ).toBeInTheDocument();

    // Category headers
    expect(screen.getByText("General")).toBeInTheDocument();
    expect(screen.getByText("Billing & Trial")).toBeInTheDocument();
    expect(screen.getByText("Features")).toBeInTheDocument();
    expect(screen.getByText("Security & Data")).toBeInTheDocument();

    // A representative question from each area
    expect(screen.getByText("What is ETHR?")).toBeInTheDocument();
    expect(screen.getByText("How long is the free trial?")).toBeInTheDocument();
    expect(screen.getByText("Does it work offline?")).toBeInTheDocument();
    expect(screen.getByText("Is my data secure?")).toBeInTheDocument();

    // The Ethiopian-calendar answer is spelled out (not just the question).
    expect(
      screen.getByText(/Gregorian and Ethiopian calendars/i),
    ).toBeInTheDocument();
  });

  it("closes with a contact CTA", () => {
    render(<FaqContent />);
    const cta = screen.getByRole("link", { name: "Contact us" });
    expect(cta).toHaveAttribute("href", "/contact");
  });
});
