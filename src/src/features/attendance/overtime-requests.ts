import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components } from "@/api/generated";
import type { PaginatedResponse } from "@/api/types";

/** Overtime asked for before it is worked (`OvertimeRequestController`). */
export type OvertimeRequest = components["schemas"]["OvertimeRequestResource"];
export type OvertimeRequestPayload =
  components["schemas"]["StoreOvertimeRequestRequest"];

/** The legal daily limit, the most one request may ask for (Art. 67). */
export const MAX_PLANNED_MINUTES = 240;

const key = ["attendance", "overtime-requests", "my"] as const;

export function useMyOvertimeRequests() {
  return useQuery<PaginatedResponse<OvertimeRequest>>({
    queryKey: key,
    queryFn: async () =>
      (
        await apiClient.get("/attendance/overtime-requests/my", {
          params: { per_page: 50 },
        })
      ).data,
  });
}

export function useRequestOvertime() {
  const queryClient = useQueryClient();

  return useMutation<OvertimeRequest, unknown, OvertimeRequestPayload>({
    mutationFn: async (payload) =>
      (await apiClient.post("/attendance/overtime-requests", payload)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: key }),
  });
}

export function useCancelOvertimeRequest() {
  const queryClient = useQueryClient();

  return useMutation<OvertimeRequest, unknown, string>({
    mutationFn: async (publicId) =>
      (await apiClient.put(`/attendance/overtime-requests/${publicId}/cancel`))
        .data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: key }),
  });
}
