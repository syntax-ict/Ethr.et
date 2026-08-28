import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { LandingContent } from "@/app/landing-content";
import { PricingContent } from "@/app/(marketing)/pricing/pricing-content";
import { FaqContent } from "@/app/(marketing)/faq/faq-content";

// Marketing pages are pure presentational client components: they only depend on
// the i18n layer (en is registered in the test setup) and next/link, both of
// which work in jsdom. No router or network is involved.

describe("Landing page", () => {
  it("renders every headline section (S06)", () => {
    render(<LandingContent />);

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

    // Social proof metrics
    expect(screen.getByText("Organizations")).toBeInTheDocument();
    expect(screen.getByText("99.9%")).toBeInTheDocument();

    // CTA banner
    expect(
      screen.getByText("Start Your 6-Month Free Trial"),
    ).toBeInTheDocument();
  });

  it("points its primary CTAs at the registration flow", () => {
    render(<LandingContent />);
    const trialLinks = screen
      .getAllByRole("link")
      .filter((a) => a.getAttribute("href") === "/register");
    expect(trialLinks.length).toBeGreaterThan(0);
  });
});

describe("Pricing page", () => {
  it("shows all three plan tiers with the popular flag (S06)", () => {
    render(<PricingContent />);

    expect(
      screen.getByRole("heading", { name: "Starter" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "Professional" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "Enterprise" }),
    ).toBeInTheDocument();

    // Professional is flagged as the popular tier.
    expect(screen.getByText("Most Popular")).toBeInTheDocument();

    // Two "Start Free Trial" CTAs (Starter + Professional) and one "Contact Sales".
    expect(screen.getAllByText("Start Free Trial")).toHaveLength(2);
    expect(screen.getByText("Contact Sales")).toBeInTheDocument();
  });

  it("renders the FAQ accordion", () => {
    render(<PricingContent />);
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
