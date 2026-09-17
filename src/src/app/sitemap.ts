import type { MetadataRoute } from "next";
import { PUBLIC_ROUTES, SITE_URL } from "@/lib/site-url";

/**
 * The seven public pages, and only those.
 *
 * Generated from `PUBLIC_ROUTES` rather than written out again, so adding a
 * marketing page in one place adds it here — the failure this replaces is a
 * sitemap that silently stops matching the site.
 *
 * No `lastModified`. Next would let us stamp `new Date()`, but that is the
 * build time, not the time the page changed: every deploy would tell crawlers
 * all seven pages had just been edited, which is the kind of signal that gets
 * ignored once it is obviously wrong. An honest omission beats a fabricated
 * timestamp, which is the same rule this branch applied to the landing page's
 * metrics.
 *
 * `changeFrequency` is likewise absent: Google has said publicly it ignores it,
 * and a value here would be a guess dressed as a fact.
 */
export default function sitemap(): MetadataRoute.Sitemap {
  return PUBLIC_ROUTES.map((route) => ({
    url: `${SITE_URL}${route === "/" ? "" : route}`,
    // The landing page is the entry point; the rest are equal to each other.
    priority: route === "/" ? 1 : 0.8,
  }));
}
