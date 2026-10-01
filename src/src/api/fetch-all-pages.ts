import { apiClient } from "@/api/client";
import type { PaginatedResponse } from "@/api/types";

/** The API's per-page ceiling (CapPagination). */
const PAGE_SIZE = 100;

/** A bound on runaway paging: 50 pages × 100 rows. */
const MAX_PAGES = 50;

/**
 * Every row of a paginated list endpoint, for pickers and reference lists
 * that must show all of a tenant's records.
 *
 * List endpoints return 25 rows unless asked (at most 100), and the pickers
 * and the Organization page took the first page as the whole list — so a
 * tenant with a 26th branch or department could neither pick it nor see it.
 */
export async function fetchAllPages<T>(
  path: string,
  params: Record<string, string | number | boolean | undefined> = {},
): Promise<T[]> {
  const rows: T[] = [];

  for (let page = 1; page <= MAX_PAGES; page++) {
    const { data } = await apiClient.get<PaginatedResponse<T>>(path, {
      params: { ...params, page, per_page: PAGE_SIZE },
    });
    rows.push(...data.data);
    if (page >= (data.meta?.last_page ?? 1)) break;
  }

  return rows;
}
