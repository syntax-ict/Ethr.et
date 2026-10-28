import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { EMPTY_SITE_CONTENT, type SiteContent } from "@/features/marketing/api";
import type { MarketingSection } from "@/lib/marketing/content";
import { LandingContent } from "@/app/(marketing)/[locale]/landing-content";
import { PricingContent } from "@/app/(marketing)/[locale]/pricing/pricing-content";
import { FaqContent } from "@/app/(marketing)/[locale]/faq/faq-content";
import { marketingFixture } from "./fixtures/marketing-content";

// FAQ is still a pure presentational client component: it depends only on the
// i18n layer (en is registered in the test setup) and next/link.
//
// Landing and Pricing are not. Pricing reads the plan catalog and Landing reads
// its sections and the published site metrics, so both need a QueryClient.
// Neither test stubs a request: the point is that the page renders correctly
// with no network at all, which is what a crawler gets and what an operator
// who has published nothing gets.
function withQuery(
  ui: React.ReactElement,
  siteContent?: Partial<SiteContent>,
  sections: MarketingSection[] = marketingFixture().sections,
) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  // Seeded into the cache rather than stubbed over the network: `useSiteContent`
  // and `useMarketingContent` read these keys, and the point of these tests is
  // what the component renders for a given payload, not how the payload
  // arrives. The landing fixture is a stable copy of the first-run content,
  // never the committed snapshot, whose values change whenever the platform
  // operator edits the page.
  if (siteContent) {
    queryClient.setQueryData("site-content".split(" "), {
      ...EMPTY_SITE_CONTENT,
      ...siteContent,
    });
  }
  queryClient.setQueryData("marketing-content".split(" "), { sections });

  return render(
    <QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>,
  );
}

describe("Landing page", () => {
  it("renders every section the operator published (S06)", () => {
    withQuery(<LandingContent />);

    // Hero, from the database rather than a translation key.
    expect(
      screen.getByRole("heading", {
        name: "Attendance, payroll and HR, built for Ethiopian organisations",
      }),
    ).toBeInTheDocument();

    // The problems ledger replaced the six-tile features grid.
    expect(
      screen.getByRole("heading", {
        name: "The work that fills an HR office, and what ETHR does with it",
      }),
    ).toBeInTheDocument();
    expect(screen.queryByText("Everything You Need")).not.toBeInTheDocument();

    // Industry chips
    expect(
      screen.getByText("Built for Ethiopian industries"),
    ).toBeInTheDocument();
    expect(screen.getByText("Government")).toBeInTheDocument();
    expect(screen.getByText("Banking")).toBeInTheDocument();
    expect(screen.getByText("Manufacturing")).toBeInTheDocument();

    // Call to action
    expect(
      screen.getByText("Start your 6-month free trial"),
    ).toBeInTheDocument();
  });

  it("sets the problems beside what ETHR does with them", () => {
    withQuery(<LandingContent />);

    const section = screen.getByRole("region", {
      name: "The work that fills an HR office, and what ETHR does with it",
    });
    const rows = section.querySelectorAll("ol > li");
    expect(rows).toHaveLength(6);
    expect(section).toHaveTextContent("Attendance on paper");
    expect(section).toHaveTextContent(
      "Staff check in from a phone, a shared kiosk, a QR code or the web",
    );
    // Every row carries a picture: an animated story when nothing was uploaded.
    expect(section.querySelectorAll("[data-scene]")).toHaveLength(6);
    expect(section.querySelectorAll(".ethr-story")).toHaveLength(6);
    expect(
      section.querySelector('[data-scene="problems/paper_attendance"]'),
    ).not.toBeNull();
  });

  it("leaves out a section the operator unpublished, and one with no rows", () => {
    const sections = marketingFixture().sections.filter(
      (s) => s.kind !== "industries",
    );
    const trust = sections.find((s) => s.kind === "problems");
    if (trust) trust.items = [];

    withQuery(<LandingContent />, undefined, sections);

    expect(
      screen.queryByText("Built for Ethiopian industries"),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("region", {
        name: "The work that fills an HR office, and what ETHR does with it",
      }),
    ).not.toBeInTheDocument();
    // The rest still renders.
    expect(
      screen.getByText("What ETHR does for your organisation"),
    ).toBeInTheDocument();
  });

  it("points its primary CTAs at the registration flow", () => {
    withQuery(<LandingContent />);
    const trialLinks = screen
      .getAllByRole("link")
      .filter((a) => a.getAttribute("href") === "/register");
    expect(trialLinks.length).toBeGreaterThan(0);
  });

  it("quotes no customer it does not have", () => {
    withQuery(<LandingContent />);

    // Five filled stars, an invented quote, and "Abebe Kebede, HR Director,
    // Addis Manufacturing PLC", a person who does not exist, attributed a
    // claim about a product they have not used. It survived a whole audit
    // because two documents said it had already been deleted and nobody read
    // the built page.
    expect(screen.queryByText(/Abebe Kebede/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Addis Manufacturing/)).not.toBeInTheDocument();
    expect(
      screen.queryByText(/replaced three separate systems/i),
    ).not.toBeInTheDocument();
  });

  it("renders a testimonial once a real one is published", () => {
    // The section is data. An operator who has a customer on the record
    // enters the quote, who said it, and the date they agreed; the API refuses
    // to publish it without all three, so this payload is the only shape the
    // page ever sees.
    withQuery(<LandingContent />, {
      testimonial_quote: "It replaced three separate systems for us.",
      testimonial_author: "A named customer",
      testimonial_role: "HR Director",
      testimonial_organisation: "A named organisation",
    });

    expect(
      screen.getByText(/It replaced three separate systems for us./),
    ).toBeInTheDocument();
    expect(screen.getByText("A named customer")).toBeInTheDocument();
    expect(
      screen.getByText("HR Director, A named organisation"),
    ).toBeInTheDocument();
  });

  it("shows no star rating, because nothing collects one", () => {
    const { container } = withQuery(<LandingContent />, {
      testimonial_quote: "It replaced three separate systems for us.",
      testimonial_author: "A named customer",
    });

    // Gold fill was what the stars were drawn in. The problems ledger's
    // stories use gold too, for overtime and Pagume, so look outside them.
    const gold = [...container.querySelectorAll(".fill-brand-accent")].filter(
      (element) => !element.closest(".ethr-story"),
    );
    expect(gold).toHaveLength(0);
    expect(container.querySelector(".lucide-star")).toBeNull();
  });

  it("renders no chrome of its own", () => {
    // The marketing layout supplies the header, <main> and footer for every
    // public page. This component carried its own set from when it lived at
    // app/page.tsx, outside the route group, and the landing page had two of
    // each until the built HTML was read.
    const { container } = withQuery(<LandingContent />);

    expect(container.querySelector("header")).toBeNull();
    expect(container.querySelector("footer")).toBeNull();
    expect(container.querySelector("main")).toBeNull();
  });

  it("describes the isolation mechanism instead of promising an absolute", () => {
    withQuery(<LandingContent />);

    expect(
      screen.queryByText(/No cross-tenant access is possible/i),
    ).not.toBeInTheDocument();
    expect(screen.getByText(/fails closed/i)).toBeInTheDocument();
  });

  it("pairs each capability with its value, claiming only what is built", () => {
    withQuery(<LandingContent />);

    const section = screen.getByRole("region", {
      name: "What ETHR does for your organisation",
    });
    const rows = section.querySelectorAll("ol > li");
    expect(rows).toHaveLength(5);
    for (const value of [
      "Establishes reliable workforce data",
      "Reduces repetitive administration",
      "Reduces routine HR requests",
      "Supports better management decisions",
    ]) {
      expect(section).toHaveTextContent(value);
    }
    expect(section).toHaveTextContent("Proclamation 1395/2025");

    // The 2026-10-17 capability audit found no performance reviews, goals,
    // training or certifications in the code, and no device verified on real
    // hardware. The page names none of them until they exist. The same list
    // is now a validation rule on the console, so an operator cannot type
    // them back in either.
    expect(section).not.toHaveTextContent(
      /performance review|appraisal|training|certif|goals|OKR/i,
    );
    expect(section).not.toHaveTextContent(/Hikvision|ZKTeco|Suprema/i);
    expect(section).not.toHaveTextContent(/Excel|multi-level|delegat/i);
  });

  it("says where data is stored, and claims no compliance on the customer's behalf", () => {
    withQuery(<LandingContent />);

    expect(
      screen.getByText("Your data, stored in Ethiopia"),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/Personal Data Protection Proclamation No\. 1321\/2024/),
    ).toBeInTheDocument();
    expect(screen.queryByText(/full compliance/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/No data leaves/i)).not.toBeInTheDocument();
    expect(
      screen.queryByText("Your Data, Your Servers"),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByText(/Every action is logged/i),
    ).not.toBeInTheDocument();
    expect(screen.queryByText(/Biometric devices/i)).not.toBeInTheDocument();
  });

  it("states no figure it cannot substantiate", () => {
    withQuery(<LandingContent />);

    // "500+ organizations", "50,000+ employees", "1M+ payrolls processed" and
    // "99.9% uptime" were all written into the component once. They are
    // columns now, and empty: with nothing published the band is not rendered.
    expect(screen.queryByText("99.9%")).not.toBeInTheDocument();
    expect(screen.queryByText("500+")).not.toBeInTheDocument();
    expect(screen.queryByText("50,000+")).not.toBeInTheDocument();
    expect(screen.queryByText("1M+")).not.toBeInTheDocument();
    expect(screen.queryByText("Organizations")).not.toBeInTheDocument();
    expect(screen.queryByText(/Now serving/i)).not.toBeInTheDocument();
  });

  it("renders the published figures when there are any", () => {
    withQuery(<LandingContent />, {
      metric_organisations: 12,
      metric_employees: 1480,
    });

    expect(screen.getByText("Organizations")).toBeInTheDocument();
    expect(screen.getByText("Employees Managed")).toBeInTheDocument();
    expect(screen.getByText(/Now serving 12 Ethiopian/)).toBeInTheDocument();
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
    expect(screen.getByText("8,999")).toBeInTheDocument();
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
