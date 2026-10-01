import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components } from "@/api/generated";
import type { PaginatedResponse } from "@/api/types";

/**
 * The staff directory: every current colleague's name, contact details and
 * placement, readable by any signed-in user. People who have resigned, been
 * terminated, retired or are suspended are not listed (`EmployeeStatus::current()`
 * on the API). `department`, `position` and `branch` are names, not objects —
 * DirectoryResource flattens them. Photos come as signed URLs only; the storage
 * path is not sent.
 */
export type DirectoryPerson = components["schemas"]["DirectoryResource"];

export interface DirectoryParams {
  search?: string;
  page?: number;
  per_page?: number;
}

const keys = {
  all: ["directory"] as const,
  list: (params: DirectoryParams) => ["directory", params] as const,
};

/**
 * One page of the directory. A resource collection, so the page count is
 * `meta.last_page`.
 */
export function useDirectory(
  params: DirectoryParams,
  options?: { enabled?: boolean; staleTime?: number },
) {
  return useQuery<PaginatedResponse<DirectoryPerson>>({
    queryKey: keys.list(params),
    queryFn: async () =>
      (
        await apiClient.get("/directory", {
          params: {
            search: params.search || undefined,
            page: params.page,
            per_page: params.per_page,
          },
        })
      ).data,
    enabled: options?.enabled ?? true,
    staleTime: options?.staleTime,
  });
}
