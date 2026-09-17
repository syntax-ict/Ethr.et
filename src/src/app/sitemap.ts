import type { MetadataRoute } from "next";
import { AVAILABLE_LOCALES, localeHref } from "@/lib/i18n/config";
import { PUBLIC_ROUTES, SITE_URL } from "@/lib/site-url";

/**
 * The seven public pages, once per available language.
 *
 * Generated from `PUBLIC_ROUTES` × `AVAILABLE_LOCALES` rather than written out
 * again, so adding a marketing page in one place adds it here — and promoting a
 * `coming_soon` locale adds a whole language — instead of leaving a sitemap that
 * silently stops matching the site.
 *
 * Every entry carries the full `hreflang` set, including its own URL. That is
 * the specified form, not redundancy: a crawler that finds `/en/pricing` has to
 * be able to see from that one entry that `/am/pricing` is the same page in
 * another language, or it treats the two as duplicates and picks one. The
 * `x-default` is the unprefixed URL, which negotiates per visitor.
 *
 * The unprefixed URLs are not listed as entries of their own: they hold no
 * content, only a redirect, and every one of them is already named here as an
 * `x-default`.
 *
 * No `lastModified`. Next would let us stamp `new Date()`, but that is the
 * build time, not the time the page changed: every deploy would tell crawlers
 * all fourteen pages had just been edited, which is the kind of signal that
 * gets ignored once it is obviously wrong. An honest omission beats a
 * fabricated timestamp, which is the same rule this branch applied to the
 * landing page's metrics.
 *
 * `changeFrequency` is likewise absent: Google has said publicly it ignores it,
 * and a value here would be a guess dressed as a fact.
 */
export default function sitemap(): MetadataRoute.Sitemap {
  const absolute = (path: string) => `${SITE_URL}${path === "/" ? "" : path}`;

  return PUBLIC_ROUTES.flatMap((route) =>
    AVAILABLE_LOCALES.map((locale) => ({
      url: absolute(localeHref(locale, route)),
      // The landing page is the entry point; the rest are equal to each other.
      priority: route === "/" ? 1 : 0.8,
      alternates: {
        languages: {
          ...Object.fromEntries(
            AVAILABLE_LOCALES.map((code) => [
              code,
              absolute(localeHref(code, route)),
            ]),
          ),
          "x-default": absolute(route),
        },
      },
    })),
  );
}
