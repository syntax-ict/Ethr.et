import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { operations } from "@/api/generated";

/**
 * The header's global search: every kind of record the caller may open, a
 * handful of each, from one request. The server decides what is in each group
 * from the caller's permissions, so a group the caller cannot see comes back
 * empty rather than missing.
 */
export type GlobalSearchResult =
  operations["search.globalSearch"]["responses"][200]["content"]["application/json"];

export type SearchEmployee = GlobalSearchResult["employees"][number];
export type SearchAttendance = GlobalSearchResult["attendance"][number];
export type SearchLeave = GlobalSearchResult["leave"][number];
export type SearchDepartment = GlobalSearchResult["departments"][number];

export const MIN_QUERY_LENGTH = 2;

export function useGlobalSearch(query: string, enabled: boolean) {
  const q = query.trim();
  return useQuery<GlobalSearchResult>({
    queryKey: ["search", q],
    enabled: enabled && q.length >= MIN_QUERY_LENGTH,
    queryFn: async ({ signal }) => {
      const { data } = await apiClient.get("/search", {
        params: { q },
        signal,
      });
      return data;
    },
    staleTime: 30 * 1000,
    // Keep the previous answer on screen while the next keystroke loads, so
    // the list does not collapse and jump on every letter.
    placeholderData: (previous) => previous,
  });
}
