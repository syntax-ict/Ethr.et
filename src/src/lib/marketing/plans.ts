import type { Plan } from "@/features/billing/api";
import snapshot from "./plans-snapshot.json";

/**
 * The plan catalog as it stood at build time.
 *
 * The pricing page reads the live catalog from `GET /api/v1/plans` — but that
 * is a client-side fetch, and `/pricing` is a prerendered static route. Without
 * a seed value the emitted HTML would contain no prices at all, so a crawler,
 * and every link preview, would see an empty pricing table. That is the same
 * class of defect this branch exists to fix, so the fix must not introduce it.
 *
 * So this file is the build-time value, used as TanStack Query's `initialData`:
 * the prerendered HTML and the first client paint both show real prices, and
 * the live catalog replaces them as soon as the fetch resolves. There is no
 * loading state on the page at any point.
 *
 * REGENERATE WITH: `node scripts/fetch-plans-snapshot.mjs` against a running
 * API. Deliberately NOT wired into `next build` — CI has no Laravel process, so
 * a build-time fetch would make the frontend build depend on the backend being
 * up, which it never has been.
 *
 * THE HONEST CAVEAT: this is a cache with a manual refresh. If someone edits a
 * plan and nobody regenerates, crawlers see the stale price until the next
 * build while humans see the live one. Once plan editing has an admin UI,
 * regenerating belongs in the deploy step. Until then the values below come
 * from `PlanSeeder`, which is also what the database is seeded from, so they
 * agree by construction.
 */
export const PLANS_SNAPSHOT: { data: Plan[] } = {
  data: snapshot.data as Plan[],
};

/**
 * When the snapshot was generated, as epoch ms — passed to TanStack Query as
 * `initialDataUpdatedAt`.
 *
 * This is load-bearing, not decoration. `initialData` without it is treated as
 * fetched *now*, and `usePlans` sets `staleTime: 30 minutes` — so the query
 * would consider the snapshot fresh and never refetch. The page would render
 * build-time prices and silently never reach the live catalog, which is exactly
 * the bug this whole change exists to remove, reintroduced one layer down.
 *
 * Dating the seed to when it was actually generated makes it stale on arrival,
 * so the page paints instantly from the snapshot and refetches on mount.
 */
export const PLANS_SNAPSHOT_GENERATED_AT: number = Date.parse(
  snapshot.generated_at,
);

/**
 * A limit column is nullable, and null means "no ceiling".
 *
 * `PlanSeeder` currently expresses Enterprise's ceiling as the sentinel
 * 999999/999/999 rather than null, so those render as literal numbers today —
 * truthful, since `PlanLimitService` enforces exactly those figures, but almost
 * certainly not what was meant. Switching the seeder to null is a data change
 * that needs no code change here, because this already handles it.
 */
export function isUnlimited(limit: number | null): boolean {
  return limit === null;
}

/**
 * Plans the public pricing page should show, in catalog order.
 *
 * The API already filters to `is_active` and orders by `sort_order`, but the
 * snapshot is a plain file and a future caller might not, so the ordering is
 * re-applied rather than assumed.
 */
export function publicPlans(plans: Plan[]): Plan[] {
  return [...plans].sort((a, b) => a.sort_order - b.sort_order);
}
