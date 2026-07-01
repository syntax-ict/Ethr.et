import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { PaginatedResponse } from "@/api/types";

// ── Types ─────────────────────────────────────────────────────────────────────

export interface AttendanceRecord {
  public_id: string;
  employee_name?: string;
  employee_public_id?: string;
  date: string;
  check_in: string | null;
  check_out: string | null;
  status: string;
  source: string;
  confidence_score: number;
  worked_minutes: number | null;
}

export interface AttendanceCorrection {
  public_id: string;
  employee_name?: string;
  employee_public_id?: string;
  date: string;
  proposed_check_in: string | null;
  proposed_check_out: string | null;
  reason: string;
  status: "pending" | "approved" | "rejected";
  created_at: string;
  reviewed_at: string | null;
}

export interface AttendanceFilters {
  page?: number;
  per_page?: number;
  "filter[date_from]"?: string;
  "filter[date_to]"?: string;
  "filter[status]"?: string;
  "filter[source]"?: string;
  "filter[employee_public_id]"?: string;
  "filter[department_public_id]"?: string;
  "filter[branch_public_id]"?: string;
}

// ── My Attendance ─────────────────────────────────────────────────────────────

export function useMyAttendance(params?: { page?: number }) {
  return useQuery<PaginatedResponse<AttendanceRecord>>({
    queryKey: ["attendance", "my", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/attendance/my", { params });
      return data;
    },
  });
}

// ── All Attendance (HR admin) ─────────────────────────────────────────────────

export function useAttendanceList(params?: AttendanceFilters) {
  return useQuery<PaginatedResponse<AttendanceRecord>>({
    queryKey: ["attendance", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/attendance", { params });
      return data;
    },
  });
}

// ── Check In / Out ────────────────────────────────────────────────────────────

export function useCheckIn() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: {
      idempotency_key: string;
      source?: string;
    }) => {
      const { data } = await apiClient.post("/attendance/check-in", payload, {
        headers: { "Idempotency-Key": payload.idempotency_key },
      });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["attendance"] });
      queryClient.invalidateQueries({ queryKey: ["dashboard"] });
    },
  });
}

export function useCheckOut() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: { idempotency_key: string }) => {
      const { data } = await apiClient.post("/attendance/check-out", payload, {
        headers: { "Idempotency-Key": payload.idempotency_key },
      });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["attendance"] });
      queryClient.invalidateQueries({ queryKey: ["dashboard"] });
    },
  });
}

// ── Manual Attendance ─────────────────────────────────────────────────────────

export function useManualAttendance() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: {
      employee_public_id: string;
      date: string;
      check_in?: string;
      check_out?: string;
      reason?: string;
      idempotency_key: string;
    }) => {
      const { data } = await apiClient.post("/attendance/manual", payload, {
        headers: { "Idempotency-Key": payload.idempotency_key },
      });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["attendance"] });
    },
  });
}

// ── Corrections ───────────────────────────────────────────────────────────────

export function useCorrections(params?: {
  page?: number;
  "filter[status]"?: string;
}) {
  return useQuery<PaginatedResponse<AttendanceCorrection>>({
    queryKey: ["attendance", "corrections", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/attendance/corrections", {
        params,
      });
      return data;
    },
  });
}

export function usePendingCorrections() {
  return useQuery<PaginatedResponse<AttendanceCorrection>>({
    queryKey: ["attendance", "corrections", "pending"],
    queryFn: async () => {
      const { data } = await apiClient.get("/attendance/corrections/pending");
      return data;
    },
  });
}

export function useSubmitCorrection() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: {
      attendance_record_public_id: string;
      proposed_check_in?: string;
      proposed_check_out?: string;
      reason: string;
    }) => {
      const { data } = await apiClient.post("/attendance/corrections", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["attendance", "corrections"],
      });
    },
  });
}

export function useApproveCorrection() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) => {
      const { data } = await apiClient.put(
        `/attendance/corrections/${publicId}/approve`,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["attendance"] });
    },
  });
}

export function useRejectCorrection() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      publicId,
      reason,
    }: {
      publicId: string;
      reason: string;
    }) => {
      const { data } = await apiClient.put(
        `/attendance/corrections/${publicId}/reject`,
        { reason },
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["attendance"] });
    },
  });
}

// ── Intelligence / Overtime ───────────────────────────────────────────────────

export function useAttendanceIntelligence() {
  return useQuery({
    queryKey: ["attendance", "intelligence"],
    queryFn: async () => {
      const { data } = await apiClient.get("/attendance/intelligence");
      return data;
    },
  });
}

export function useAttendanceOvertime(params?: {
  month?: string;
  department_id?: string;
}) {
  return useQuery({
    queryKey: ["attendance", "overtime", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/attendance/overtime", { params });
      return data;
    },
  });
}
