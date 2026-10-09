import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { fetchAllPages } from "@/api/fetch-all-pages";
import type { components } from "@/api/generated";
import type { PaginatedResponse } from "@/api/types";

/**
 * Two endpoints serve this shape: `/audit-logs` (one tenant's own trail,
 * `settings.manage`) and `/admin/audit` (every tenant's, `admin.manage`).
 * Both return an AuditLogResource collection, so pages come from
 * `meta.last_page`.
 */
export type AuditLogEntry = components["schemas"]["AuditLogResource"];

export type AuditLogEndpoint = "/audit-logs" | "/admin/audit";

export interface AuditLogFilters {
  action?: string;
  from?: string;
  to?: string;
}

function filterParams(filters: AuditLogFilters): Record<string, string> {
  const params: Record<string, string> = {};
  if (filters.action) params["filter[action]"] = filters.action;
  if (filters.from) params["filter[from]"] = filters.from;
  if (filters.to) params["filter[to]"] = filters.to;
  return params;
}

export function useAuditLogs(
  endpoint: AuditLogEndpoint,
  queryKey: string,
  params: AuditLogFilters & { page: number; per_page?: number },
) {
  const { page, per_page = 50, ...filters } = params;

  return useQuery<PaginatedResponse<AuditLogEntry>>({
    queryKey: [queryKey, page, filters],
    queryFn: async () =>
      (
        await apiClient.get(endpoint, {
          params: { ...filterParams(filters), page, per_page },
        })
      ).data,
  });
}

/**
 * Every entry matching the filters, for an export. The screen shows 50 at a
 * time; an export of what is on screen is not an audit export.
 */
export function fetchAllAuditLogs(
  endpoint: AuditLogEndpoint,
  filters: AuditLogFilters,
): Promise<AuditLogEntry[]> {
  return fetchAllPages<AuditLogEntry>(endpoint, filterParams(filters));
}
