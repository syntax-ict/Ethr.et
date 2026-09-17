import { FAQ_ITEMS } from "@/app/(marketing)/[locale]/faq/faq-items";
import { localeHref } from "@/lib/i18n/config";
import { serverTranslate } from "@/lib/i18n/public-dictionary";
import { SITE_URL } from "@/lib/site-url";

/**
 * Structured data for the public pages.
 *
 * Built on the server from the same dictionary the page renders from, so the
 * markup and the JSON-LD cannot describe different things — which is both
 * Google's stated requirement and the failure mode of every hand-written copy.
 *
 * What is deliberately absent is the point. `Organization` carries no `logo`,
 * `address`, `telephone` or `sameAs`: those live in `platform_settings`, they
 * are the operator's to fill in, and they are unreachable from a build that has
 * no database. Emitting a placeholder logo or a guessed address would put back
 * precisely what this branch spent its first half deleting from the landing
 * page. They join this object the day site content gets the build-time snapshot
 * the plan catalog already has.
 *
 * There is no `aggregateRating`, no `Review` and no `Product` offer either. A
 * rating needs reviewers, and the plan catalog's prices are already published as
 * ordinary page content that a crawler reads.
 */
export function websiteJsonLd(locale: string) {
  return {
    "@context": "https://schema.org",
    "@type": "WebSite",
    name: serverTranslate(
      locale,
      "marketing.meta.site.title",
      "ETHR — Ethiopian Workforce Operating System",
    ),
    url: `${SITE_URL}${localeHref(locale, "/")}`,
    inLanguage: locale,
    // No `potentialAction`/SearchAction: the public site has no search.
  };
}

export function organizationJsonLd(locale: string) {
  return {
    "@context": "https://schema.org",
    "@type": "Organization",
    name: "ETHR",
    url: SITE_URL,
    description: serverTranslate(
      locale,
      "marketing.meta.site.description",
      "Enterprise-grade, multi-tenant HR management system built for Ethiopian organizations.",
    ),
  };
}

/**
 * The FAQ, question for question, from the list the page itself renders.
 *
 * `serverTranslate` rather than the lazy loader, because these keys carry no
 * fallback — a timing-dependent lookup would put `marketing.faq_page.what_is_q`
 * into the structured data, which is worse than omitting it entirely.
 */
export function faqJsonLd(locale: string) {
  return {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    inLanguage: locale,
    mainEntity: FAQ_ITEMS.map((item) => ({
      "@type": "Question",
      name: serverTranslate(locale, `marketing.faq_page.${item}_q`),
      acceptedAnswer: {
        "@type": "Answer",
        text: serverTranslate(locale, `marketing.faq_page.${item}_a`),
      },
    })),
  };
}
