import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { paths } from "@/api/generated";

// Shapes come from the generated contract.

export type NavigationBadges =
  paths["/navigation/badges"]["get"]["responses"][200]["content"]["application/json"];

/**
 * The two counts the sidebar and the phone tab bar draw: pending approvals and
 * attendance punches waiting for an integrity review. A count the caller may
 * not see arrives as null.
 *
 * This replaces the sidebar's use of `/dashboard/manager`, the heaviest
 * dashboard call, which it fetched on every page only to read one number
 * (settings review F11).
 *
 * The query sits under `["dashboard"]` so the invalidation that every approval
 * and integrity decision already sends refreshes it, as it did before.
 *
 * @param enabled Gate the request on the caller's role: a person with nothing
 * to count has no badge to draw, and a super admin has no tenant to count in.
 */
export function useNavigationBadges(enabled = true) {
  return useQuery<NavigationBadges>({
    queryKey: ["dashboard", "navigation-badges"],
    queryFn: async () => {
      const { data } = await apiClient.get("/navigation/badges");
      return data;
    },
    staleTime: 5 * 60 * 1000,
    // The counts move while the page is open: refreshed every minute while the
    // tab is visible, and on every decision (audit N80).
    refetchInterval: 60 * 1000,
    enabled,
  });
}
