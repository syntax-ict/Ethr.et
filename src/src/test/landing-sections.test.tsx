import { describe, expect, it } from "vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { LandingContent } from "@/app/(marketing)/[locale]/landing-content";
import { CapabilitiesSection } from "@/components/marketing/landing/capabilities-section";
import { STORY_SLUGS } from "@/components/marketing/landing/problem-stories";
import { SCENE_KEYS } from "@/components/marketing/landing/scenes";
import { RouteLocaleProvider } from "@/lib/i18n/route-locale";
import type { MarketingSection } from "@/lib/marketing/content";
import { fixtureImage, marketingFixture } from "./fixtures/marketing-content";

function withSections(
  ui: React.ReactElement,
  sections: MarketingSection[],
  locale = "en",
) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  queryClient.setQueryData("marketing-content".split(" "), { sections });
  return render(
    <QueryClientProvider client={queryClient}>
      <RouteLocaleProvider locale={locale}>{ui}</RouteLocaleProvider>
    </QueryClientProvider>,
  );
}

describe("landing sections", () => {
  it("draws an uploaded picture with its srcset, size and localised alt text", () => {
    const sections = marketingFixture().sections;
    const capabilities = sections.find((s) => s.kind === "capabilities")!;
    const records = capabilities.items[0];
    records.image = fixtureImage(records.public_id);

    withSections(<CapabilitiesSection section={capabilities} />, sections);

    const img = screen.getByRole("img", {
      name: "An HR officer with a complete employee file",
    });
    expect(img).toHaveAttribute("srcset", records.image.srcset);
    expect(img).toHaveAttribute("width", "1600");
    expect(img).toHaveAttribute("height", "1000");
    expect(img).toHaveAttribute("loading", "lazy");
    // The other four rows keep their drawn scenes.
    expect(document.querySelectorAll("[data-scene]")).toHaveLength(4);
  });

  it("reads the Amharic alt text under the Amharic route", () => {
    const sections = marketingFixture().sections;
    const capabilities = sections.find((s) => s.kind === "capabilities")!;
    capabilities.items[0].image = fixtureImage(capabilities.items[0].public_id);

    withSections(
      <CapabilitiesSection section={capabilities} />,
      sections,
      "am",
    );

    expect(
      screen.getByRole("img", { name: "ሙሉ የሠራተኛ ፋይል የያዘች የሰው ሀብት ባለሙያ" }),
    ).toBeInTheDocument();
    expect(screen.getByText("የሠራተኛ መዝገብና የድርጅት መዋቅር")).toBeInTheDocument();
    expect(screen.getByText("አስተማማኝ የሠራተኛ መረጃ ይገነባል")).toBeInTheDocument();
  });

  it("renders the whole page in Amharic from the same payload", () => {
    withSections(<LandingContent />, marketingFixture().sections, "am");

    expect(
      screen.getByRole("heading", {
        name: "ለኢትዮጵያ ድርጅቶች የተሠራ የመገኘት፣ የደመወዝና የሰው ሀብት መድረክ",
      }),
    ).toBeInTheDocument();
    expect(screen.getByText("በወረቀት ላይ መገኘት")).toBeInTheDocument();
    expect(screen.getByText("መንግሥት")).toBeInTheDocument();
    // A row with no Amharic falls back to English rather than to nothing.
    expect(screen.getByText("Healthcare")).toBeInTheDocument();
  });

  it("falls back to English for a row with no Amharic, never to a raw key", () => {
    withSections(<LandingContent />, marketingFixture().sections, "am");
    expect(document.body.textContent).not.toMatch(/marketing\.[a-z_.]+/);
  });

  it("has a drawn scene for every seeded capability, and a story for every problem", () => {
    const sections = marketingFixture().sections;
    const expected: string[] = [];
    for (const section of sections) {
      if (section.kind === "capabilities") {
        for (const item of section.items)
          expected.push(`${section.kind}/${item.slug}`);
      }
      if (section.kind === "problems") {
        for (const item of section.items)
          expect(STORY_SLUGS).toContain(item.slug);
      }
    }
    for (const key of expected) expect(SCENE_KEYS).toContain(key);
  });

  it("plays the hero story when no hero picture is uploaded, and the picture when one is", () => {
    withSections(<LandingContent />, marketingFixture().sections);
    expect(
      document.querySelector('.ethr-story[data-scene="hero/hero"]'),
    ).not.toBeNull();
    cleanup();

    const sections = marketingFixture().sections;
    const hero = sections.find((s) => s.kind === "hero")!;
    hero.image = fixtureImage("hero");
    withSections(<LandingContent />, sections);
    expect(document.querySelector('[data-scene="hero/hero"]')).toBeNull();
    expect(
      document.querySelector('img[src="' + hero.image.url + '"]'),
    ).not.toBeNull();
  });

  it("plays a plain story for a row added later that no story was drawn for", () => {
    const sections = marketingFixture().sections;
    const problems = sections.find((s) => s.kind === "problems")!;
    problems.items[0].slug = "something_new";

    withSections(<LandingContent />, sections);

    expect(
      document.querySelector('[data-scene="problems/something_new"]'),
    ).not.toBeNull();
  });

  it("links a row to its page under the current locale", () => {
    withSections(<LandingContent />, marketingFixture().sections, "am");

    const links = screen
      .getAllByRole("link", { name: /ተጨማሪ ያንብቡ/ })
      .map((a) => a.getAttribute("href"));
    expect(links).toContain("/am/attendance-system");
    expect(links).toContain("/am/payroll-software");
  });
});
