"use client";

import { TrustedBy } from "@/components/marketing/trusted-by";
import { CapabilitiesSection } from "@/components/marketing/landing/capabilities-section";
import { CtaSection } from "@/components/marketing/landing/cta-section";
import { HowItWorksSection } from "@/components/marketing/landing/how-it-works-section";
import { IndustriesSection } from "@/components/marketing/landing/industries-section";
import { LandingHero } from "@/components/marketing/landing/landing-hero";
import { MetricsBand } from "@/components/marketing/landing/metrics-band";
import { ProblemsSection } from "@/components/marketing/landing/problems-section";
import { TrustSection } from "@/components/marketing/landing/trust-section";
import {
  pickLocalised,
  useMarketingContent,
  useSiteContent,
} from "@/features/marketing/api";
import { sectionOf } from "@/lib/marketing/content";
import { useT } from "@/lib/i18n/useT";

/**
 * The landing page, composed from what the platform operator published.
 *
 * Every section's words come from `GET /api/v1/marketing-content`, seeded
 * from the build-time snapshot so the prerendered HTML is never empty. A
 * section the operator unpublished, or one with no rows, is not drawn. The
 * order of the sections is fixed here: it is the page's anatomy, and
 * changing it is a design decision.
 *
 * No <MarketingHeader>, <main> or <MarketingFooter> here: they belong to
 * `(marketing)/[locale]/layout.tsx`, which wraps every public page, and a
 * second set rendered two headers and two <main> landmarks once before.
 */
export function LandingContent() {
  const { locale } = useT();
  const { sections } = useMarketingContent();
  const site = useSiteContent();

  const hero = sectionOf(sections, "hero");
  const problems = sectionOf(sections, "problems");
  const capabilities = sectionOf(sections, "capabilities");
  const howItWorks = sectionOf(sections, "how_it_works");
  const industries = sectionOf(sections, "industries");
  const trust = sectionOf(sections, "trust");
  const cta = sectionOf(sections, "cta");

  /**
   * The customer quote, if a real customer has given one. `testimonial_quote`
   * is non-null only when the API had an author and a recorded consent date
   * to go with it, so this single check is the whole gate. No star rating:
   * nothing collects one.
   */
  const testimonialQuote = pickLocalised(
    locale,
    site.testimonial_quote_am,
    site.testimonial_quote,
  );
  const testimonialAttribution = [
    pickLocalised(locale, site.testimonial_role_am, site.testimonial_role),
    site.testimonial_organisation,
  ]
    .filter((part): part is string => Boolean(part))
    .join(", ");

  return (
    <>
      {hero && (
        <LandingHero
          section={hero}
          organisationsCount={site.metric_organisations}
        />
      )}

      <MetricsBand site={site} />

      {/* Organisations that agreed to be named; drawn only when there are any. */}
      <TrustedBy />

      {problems && problems.items.length > 0 && (
        <ProblemsSection section={problems} />
      )}

      {capabilities && capabilities.items.length > 0 && (
        <CapabilitiesSection section={capabilities} />
      )}

      {howItWorks && howItWorks.items.length > 0 && (
        <HowItWorksSection section={howItWorks} />
      )}

      {industries && industries.items.length > 0 && (
        <IndustriesSection section={industries} />
      )}

      {trust && <TrustSection section={trust} />}

      {testimonialQuote && site.testimonial_author && (
        <section className="py-20 sm:py-24">
          <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
            <blockquote className="text-xl font-medium leading-relaxed text-foreground sm:text-2xl">
              &ldquo;{testimonialQuote}&rdquo;
            </blockquote>
            <div className="mt-6">
              <p className="font-semibold text-foreground">
                {site.testimonial_author}
              </p>
              {testimonialAttribution && (
                <p className="text-sm text-muted-foreground">
                  {testimonialAttribution}
                </p>
              )}
            </div>
          </div>
        </section>
      )}

      {cta && <CtaSection section={cta} />}
    </>
  );
}
