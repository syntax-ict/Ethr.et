import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { fetchAllPages } from "@/api/fetch-all-pages";
import type { components } from "@/api/generated";
import type { PaginatedResponse } from "@/api/types";

export type LeaveType = components["schemas"]["LeaveTypeResource"];
export type AccrualType = components["schemas"]["AccrualType"];
export type LeaveTypePayload = components["schemas"]["StoreLeaveTypeRequest"];
export type LeaveTypeUpdate = components["schemas"]["UpdateLeaveTypeRequest"];

export interface LeaveBalance {
  leave_type: { name: string; code: string; public_id: string } | string;
  entitled_days: number;
  used_days: number;
  remaining_days: number;
}

export interface LeaveRequest {
  public_id: string;
  leave_type: { name: string; code: string; public_id: string } | string;
  start_date: string;
  end_date: string;
  days: number;
  reason: string | null;
  status: string;
  created_at: string;
}

export function useLeaveBalance() {
  return useQuery<LeaveBalance[]>({
    queryKey: ["leave", "balance"],
    queryFn: async () => {
      const { data } = await apiClient.get("/leave/balance");
      return data;
    },
    staleTime: 5 * 60 * 1000,
  });
}

export function useMyLeaveRequests(params?: { page?: number }) {
  return useQuery<PaginatedResponse<LeaveRequest>>({
    queryKey: ["leave", "my", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/leave/my", { params });
      return data;
    },
    staleTime: 2 * 60 * 1000,
  });
}

export function useTeamLeaveRequests(params?: { page?: number }) {
  return useQuery<PaginatedResponse<LeaveRequest>>({
    queryKey: ["leave", "team", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/leave/team", { params });
      return data;
    },
    staleTime: 2 * 60 * 1000,
  });
}

export function useLeaveTypes() {
  return useQuery<PaginatedResponse<LeaveType>>({
    queryKey: ["leave-types"],
    queryFn: async () => {
      const { data } = await apiClient.get("/leave-types");
      return data;
    },
    staleTime: 30 * 60 * 1000,
  });
}

/**
 * Every leave type, for the management page. `GET /leave-types` pages at 25;
 * `useLeaveTypes` above still reads only the first page.
 */
export function useAllLeaveTypes() {
  return useQuery<LeaveType[]>({
    queryKey: ["leave-types", "all"],
    queryFn: () => fetchAllPages<LeaveType>("/leave-types"),
  });
}

function useLeaveTypeMutation<TVars, TData>(
  mutationFn: (vars: TVars) => Promise<TData>,
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["leave-types"] });
    },
  });
}

export function useCreateLeaveType() {
  return useLeaveTypeMutation(
    async (payload: LeaveTypePayload): Promise<LeaveType> =>
      (await apiClient.post("/leave-types", payload)).data,
  );
}

export function useUpdateLeaveType() {
  return useLeaveTypeMutation(
    async (vars: {
      publicId: string;
      payload: LeaveTypeUpdate;
    }): Promise<LeaveType> =>
      (await apiClient.put(`/leave-types/${vars.publicId}`, vars.payload)).data,
  );
}

export function useDeleteLeaveType() {
  return useLeaveTypeMutation(async (publicId: string): Promise<void> => {
    await apiClient.delete(`/leave-types/${publicId}`);
  });
}

export function useSubmitLeave() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: {
      leave_type_public_id: string;
      start_date: string;
      end_date: string;
      reason?: string;
    }) => {
      const { data } = await apiClient.post("/leave/request", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["leave"] });
    },
  });
}

export function useApproveLeave() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) => {
      const { data } = await apiClient.put(`/leave/${publicId}/approve`);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["leave"] });
    },
  });
}

export function useRejectLeave() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      publicId,
      reason,
    }: {
      publicId: string;
      reason: string;
    }) => {
      const { data } = await apiClient.put(`/leave/${publicId}/reject`, {
        reason,
      });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["leave"] });
    },
  });
}

export function useCancelLeave() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) => {
      const { data } = await apiClient.put(`/leave/${publicId}/cancel`);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["leave"] });
    },
  });
}

export function useLeaveRequest(publicId: string) {
  return useQuery<LeaveRequest>({
    queryKey: ["leave", "request", publicId],
    queryFn: async () => {
      const { data } = await apiClient.get(`/leave/${publicId}`);
      return data;
    },
    enabled: !!publicId,
  });
}

// Derive display-safe leave type name from either object or string
export function leaveTypeName(leaveType: LeaveRequest["leave_type"]): string {
  if (typeof leaveType === "string") return leaveType;
  return leaveType?.name ?? "Leave";
}
