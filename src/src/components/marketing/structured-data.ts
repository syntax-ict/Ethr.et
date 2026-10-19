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
    // The site's name, not its title: Google reads `WebSite.name` as the name
    // to show above a result, and a title with a tagline is not one.
    name: "ETHR",
    alternateName: ["ETHR HR", "ETHR Ethiopia"],
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
    // Build-time facts, not operator content: the names people search for, to
    // tell ETHR apart from the "ETHR" exchange-traded fund that fills results
    // for the bare name, and the market it serves (2026-10-19).
    alternateName: ["ETHR HR", "ETHR Ethiopia"],
    areaServed: { "@type": "Country", name: "Ethiopia" },
    url: SITE_URL,
    description: serverTranslate(
      locale,
      "marketing.meta.site.description",
      "HR, payroll, attendance and leave software for Ethiopian organizations, with Ethiopian income tax, pension and the Ethiopian calendar built in.",
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

/**
 * ETHR as a product: what it is, where it runs, which market it serves.
 *
 * No `offers` and no `aggregateRating`, for the reasons the file header gives:
 * prices are already ordinary page content, and a rating needs reviewers.
 * Google shows no rich result for an application without them; this node
 * still tells it what the site is, which the bare brand name cannot.
 */
export function softwareApplicationJsonLd(locale: string) {
  return {
    "@context": "https://schema.org",
    "@type": "SoftwareApplication",
    name: "ETHR",
    applicationCategory: "BusinessApplication",
    applicationSubCategory: "Human resources and payroll",
    operatingSystem: "Web, Android, iOS",
    inLanguage: locale,
    url: `${SITE_URL}${localeHref(locale, "/")}`,
    description: serverTranslate(
      locale,
      "marketing.meta.site.description",
      "HR, payroll, attendance and leave software for Ethiopian organizations, with Ethiopian income tax, pension and the Ethiopian calendar built in.",
    ),
    areaServed: { "@type": "Country", name: "Ethiopia" },
  };
}

/**
 * Home, then the page: the trail a search result shows under the title. The
 * page's name is its translated title, the same string `marketingMetadata`
 * puts in the head.
 */
export function breadcrumbJsonLd(
  locale: string,
  path: string,
  page: string,
  fallbackTitle: string,
) {
  const name = serverTranslate(
    locale,
    `marketing.meta.${page}.title`,
    fallbackTitle,
  );

  return {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      {
        "@type": "ListItem",
        position: 1,
        name: "ETHR",
        item: `${SITE_URL}${localeHref(locale, "/")}`,
      },
      {
        "@type": "ListItem",
        position: 2,
        name,
        item: `${SITE_URL}${localeHref(locale, path)}`,
      },
    ],
  };
}
