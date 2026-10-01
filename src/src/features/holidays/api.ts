import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { fetchAllPages } from "@/api/fetch-all-pages";
import type { components, operations } from "@/api/generated";

export type Holiday = components["schemas"]["HolidayResource"];
export type HolidayPayload = components["schemas"]["StoreHolidayRequest"];
export type AutoDetectResult =
  operations["holiday.autoDetect"]["responses"][200]["content"]["application/json"];

const keys = { all: ["holidays"] as const };

/**
 * Every holiday on record, oldest first. `GET /holidays` pages at 25 ordered
 * by date, and a year of Ethiopian public holidays is ~14 — so the page used
 * to show the oldest 25 and, from a tenant's second year, never this year's.
 */
export function useHolidays() {
  return useQuery<Holiday[]>({
    queryKey: keys.all,
    queryFn: () => fetchAllPages<Holiday>("/holidays"),
  });
}

function useHolidayMutation<TVars, TData>(
  mutationFn: (vars: TVars) => Promise<TData>,
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: keys.all });
    },
  });
}

export function useCreateHoliday() {
  return useHolidayMutation(
    async (payload: HolidayPayload): Promise<Holiday> =>
      (await apiClient.post("/holidays", payload)).data,
  );
}

export function useDeleteHoliday() {
  return useHolidayMutation(async (publicId: string): Promise<void> => {
    await apiClient.delete(`/holidays/${publicId}`);
  });
}

/** Add the computed national holidays for the current year. */
export function useAutoDetectHolidays() {
  return useHolidayMutation(
    async (): Promise<AutoDetectResult> =>
      (await apiClient.post("/holidays/auto-detect")).data,
  );
}
