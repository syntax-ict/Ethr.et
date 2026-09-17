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
