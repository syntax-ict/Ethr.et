import type { components } from "@/api/generated";
import snapshot from "./content-snapshot.json";

export type MarketingSection =
  components["schemas"]["MarketingSectionResource"];
export type MarketingItem = components["schemas"]["MarketingItemResource"];
export type MarketingImage = NonNullable<MarketingItem["image"]>;
export type MarketingSectionKind =
  | "hero"
  | "problems"
  | "capabilities"
  | "how_it_works"
  | "industries"
  | "trust"
  | "cta";

/**
 * The landing page's content as it stood at build time.
 *
 * `/en` and `/am` are prerendered static routes that read the sections from
 * `GET /api/v1/marketing-content` on the client. Without a seed value the
 * emitted HTML would carry no headings and no rows, so a crawler and every
 * link preview would see an empty page. This file is the seed, used as
 * TanStack Query's `initialData`: the prerendered HTML and the first client
 * paint both show real content, and the live rows replace them as soon as the
 * fetch resolves. There is no loading state on the page at any point.
 *
 * REGENERATE WITH: `node scripts/fetch-marketing-snapshot.mjs --api <url>`,
 * against production before cutting a release, so the HTML carries what the
 * platform operator last published. Deliberately NOT wired into `next build`:
 * CI has no Laravel process.
 *
 * Nothing compares these values to the seeder: once the operator edits the
 * page, the snapshot is regenerated from production and rightly differs from
 * first-run content. `MarketingSnapshotFreshnessTest` checks its shape, and
 * `marketing-content-snapshot.test.ts` checks it makes no forbidden claim.
 */
export const CONTENT_SNAPSHOT: { sections: MarketingSection[] } = {
  sections: snapshot.data.sections as unknown as MarketingSection[],
};

/**
 * When the snapshot was generated, as epoch ms, passed to TanStack Query as
 * `initialDataUpdatedAt` so the seed is stale on arrival and the page
 * refetches on mount rather than treating build-time content as fresh.
 */
export const CONTENT_SNAPSHOT_GENERATED_AT: number = Date.parse(
  snapshot.generated_at,
);

/** The section of a kind, or undefined when it is unpublished or absent. */
export function sectionOf(
  sections: MarketingSection[],
  kind: MarketingSectionKind,
): MarketingSection | undefined {
  return sections.find((s) => s.kind === kind);
}
