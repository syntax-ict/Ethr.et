import type { MetadataRoute } from "next";
import { SITE_URL } from "@/lib/site-url";

/**
 * There was no robots.txt at all, so every crawler fell back to "index
 * everything" — including the 64 authenticated dashboard routes, the admin
 * console and the auth flow. None of those render anything useful to a crawler
 * (they redirect to login), but they consume crawl budget and surface as
 * thin-content URLs in search results.
 *
 * Disallow is not access control. Everything below is already behind
 * authentication; this stops well-behaved crawlers wasting time, and nothing
 * more. Listing a path here does tell a reader it exists, which is why the list
 * names route prefixes that are already discoverable from the login page rather
 * than anything sensitive.
 */
/**
 * Required for `output: "export"`, and harmless without it.
 *
 * Same directive and same reason as `app/manifest.ts` — a metadata route is
 * dynamic by default, and `next build` under static export stops with
 * "export const dynamic = \"force-static\" ... not configured on route
 * '/robots.txt'". Measured 2026-09-26; `manifest.ts` was found in 2026-09-18's
 * pass and this one was not, because that build stopped at the first blocker and
 * never reached this route.
 *
 * It changes nothing under `output: "standalone"`: the function below reads
 * `SITE_URL` and returns a constant, with no request-time input, so forcing it
 * static is what it already effectively was.
 */
export const dynamic = "force-static";

export default function robots(): MetadataRoute.Robots {
  return {
    rules: [
      {
        userAgent: "*",
        allow: "/",
        disallow: [
          "/api/",
          "/dashboard",
          "/admin",
          "/login",
          "/register",
          "/forgot-password",
          "/reset-password",
        ],
      },
    ],
    sitemap: `${SITE_URL}/sitemap.xml`,
    host: SITE_URL,
  };
}
