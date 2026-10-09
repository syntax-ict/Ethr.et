import { useQuery } from "@tanstack/react-query";
import type { components } from "@/api/generated";

/*
 * The public plan catalog, in a module of its own so the pricing page can read
 * it without importing `features/billing/api.ts` — whose static import of the
 * shared axios client put the HTTP client on a public page (measured from the
 * built `/en/pricing`, 2026-10-03). `billing/api.ts` re-exports both names, so
 * the dashboard's imports are unchanged.
 *
 * A plain GET is enough: `/plans` is public, unauthenticated, not CSRF-checked,
 * and the same for every tenant and language.
 */

/**
 * The public plan catalog row, exactly as `GET /api/v1/plans` sends it.
 *
 * `PlanResource` exists so the contract stops promising `is_active`,
 * `created_at` and `updated_at` that the endpoint never carried. The admin-only
 * fields live on `AdminPlan` in `features/admin/api.ts`.
 */
export type Plan = components["schemas"]["PlanResource"];

/**
 * `options.initialData` exists for the public pricing page, which is a
 * prerendered static route: without a seed value its HTML would ship with no
 * prices, so a crawler and every link preview would see an empty pricing table.
 * It passes the committed build-time snapshot, and the live catalog replaces it
 * when the fetch resolves. Authenticated callers pass nothing and are unchanged.
 */
export function usePlans(options?: {
  initialData?: { data: Plan[] };
  /**
   * When `initialData` was produced, as epoch ms. Required alongside it:
   * without it TanStack treats the seed as fetched now, and `staleTime` below
   * would then suppress the refetch entirely — the caller would render its seed
   * forever and never see the live catalog.
   */
  initialDataUpdatedAt?: number;
}) {
  return useQuery<{ data: Plan[] }>({
    queryKey: ["plans"],
    queryFn: async () => {
      const response = await fetch("/api/v1/plans", {
        headers: { Accept: "application/json" },
      });
      if (!response.ok) {
        throw new Error(`plans answered ${response.status}`);
      }
      return (await response.json()) as { data: Plan[] };
    },
    staleTime: 30 * 60 * 1000,
    initialData: options?.initialData,
    initialDataUpdatedAt: options?.initialDataUpdatedAt,
  });
}
