import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { EMPTY_SITE_CONTENT, type SiteContent } from "@/features/marketing/api";
import { PricingContent } from "@/app/(marketing)/[locale]/pricing/pricing-content";
import { ContactContent } from "@/app/(marketing)/[locale]/contact/contact-content";
import { MarketingFooter } from "@/components/layouts/marketing-footer";
import { TooltipProvider } from "@/components/ui/tooltip";
import { RouteLocaleProvider } from "@/lib/i18n/route-locale";
import { registerLocale } from "@/lib/i18n/translations";
import om from "@/lib/i18n/locales/om.json";
import plansSnapshot from "@/lib/marketing/plans-snapshot.json";

/**
 * The platform's own wording on /om (2026-11-03): the plan catalogue and the
 * site-wide labels each carry an Oromo twin, and an Oromo reader sees it, or
 * the English when the operator has not written one. Never the Amharic.
 */
registerLocale("om", om as Record<string, string>);

function renderOm(ui: React.ReactElement, site?: Partial<SiteContent>) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  if (site) {
    queryClient.setQueryData("site-content".split(" "), {
      ...EMPTY_SITE_CONTENT,
      ...site,
    });
  }
  return render(
    <QueryClientProvider client={queryClient}>
      <TooltipProvider>
        <RouteLocaleProvider locale="om">{ui}</RouteLocaleProvider>
      </TooltipProvider>
    </QueryClientProvider>,
  );
}

describe("the platform's wording in Oromo", () => {
  it("prices the plans with their Oromo description and selling points", () => {
    const starter = plansSnapshot.data.find((p) => p.slug === "starter")!;
    expect(starter.description_om).toBeTruthy();

    renderOm(<PricingContent />);

    expect(screen.getByText(starter.description_om!)).toBeInTheDocument();
    for (const point of starter.marketing_features_om ?? []) {
      expect(screen.getByText(point)).toBeInTheDocument();
    }
    expect(screen.queryByText(starter.description_am!)).not.toBeInTheDocument();
  });

  it("writes the footer's name and tagline in Oromo, and English where there is none", () => {
    renderOm(<MarketingFooter />, {
      platform_name: "ETHR",
      platform_name_om: null,
      tagline: "HR for Ethiopian organisations",
      tagline_am: "ለኢትዮጵያ ድርጅቶች የሰው ሃብት",
      tagline_om: "Qabeenya Namaa dhaabbilee Itoophiyaatiif",
    });

    expect(
      screen.getByText("Qabeenya Namaa dhaabbilee Itoophiyaatiif"),
    ).toBeInTheDocument();
    expect(screen.queryByText("ለኢትዮጵያ ድርጅቶች የሰው ሃብት")).not.toBeInTheDocument();
    expect(screen.getAllByText("ETHR").length).toBeGreaterThan(0);
  });

  it("gives the office address in Oromo on the contact page", () => {
    renderOm(<ContactContent />, {
      contact_email: "hello@ethr.et",
      office_address: "Addis Ababa, Ethiopia",
      office_address_am: "አዲስ አበባ፣ ኢትዮጵያ",
      office_address_om: "Finfinnee, Itoophiyaa",
    });

    expect(screen.getByText("Finfinnee, Itoophiyaa")).toBeInTheDocument();
    expect(screen.queryByText("Addis Ababa, Ethiopia")).not.toBeInTheDocument();
  });
});
