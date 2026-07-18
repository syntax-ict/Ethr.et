import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { PaginatedResponse } from "@/api/types";

export interface Shift {
  public_id: string;
  name: string;
  name_am: string | null;
  start_time: string;
  end_time: string;
  crosses_midnight: boolean;
  grace_minutes: number;
  early_departure_minutes: number;
  break_minutes: number;
  working_days: number[];
  is_default: boolean;
  is_active: boolean;
}

export interface ShiftAssignment {
  employee_public_id: string;
  shift_public_id: string;
  effective_from: string;
  effective_to?: string | null;
}

export interface ShiftScheduleEntry {
  employee_public_id: string;
  employee_name: string;
  shift: Shift | null;
  date: string;
}

export function useShifts(params?: { page?: number; per_page?: number }) {
  return useQuery<PaginatedResponse<Shift>>({
    queryKey: ["shifts", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/shifts", { params });
      return data;
    },
    staleTime: 30 * 60 * 1000,
  });
}

export function useShift(publicId: string) {
  return useQuery<Shift>({
    queryKey: ["shifts", publicId],
    queryFn: async () => {
      const { data } = await apiClient.get(`/shifts/${publicId}`);
      return data;
    },
    enabled: !!publicId,
    staleTime: 30 * 60 * 1000,
  });
}

export function useShiftSchedule(params?: {
  week?: string;
  department_id?: string;
}) {
  return useQuery<ShiftScheduleEntry[]>({
    queryKey: ["shifts", "schedule", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/shifts/schedule", { params });
      return data;
    },
    staleTime: 5 * 60 * 1000,
  });
}

export function useCreateShift() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (
      payload: Omit<Shift, "public_id" | "is_default" | "is_active"> &
        Partial<Pick<Shift, "is_default" | "is_active">>,
    ) => {
      const { data } = await apiClient.post("/shifts", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["shifts"] });
    },
  });
}

export function useUpdateShift(publicId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: Partial<Shift>) => {
      const { data } = await apiClient.put(`/shifts/${publicId}`, payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["shifts"] });
    },
  });
}

export function useDeleteShift() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) => {
      await apiClient.delete(`/shifts/${publicId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["shifts"] });
    },
  });
}

export function useAssignShift() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: ShiftAssignment) => {
      const { data } = await apiClient.post("/shifts/assign", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["shifts"] });
    },
  });
}
