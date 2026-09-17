import { renderOgCard } from "@/components/marketing/og-card";

/**
 * The social card, at a URL that does not move between builds.
 *
 * `force-static` so it is generated once at build time and written out as a
 * plain file — the same thing the `opengraph-image.tsx` convention produces,
 * minus the content-hashed path that made it impossible to name from
 * `generateMetadata`. It also keeps working under `output: "export"`, where a
 * dynamic route handler would not.
 */
export const dynamic = "force-static";

export function GET() {
  return renderOgCard();
}
