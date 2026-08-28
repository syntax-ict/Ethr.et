import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { PaginatedResponse } from "@/api/types";
import type { Shift } from "./api";

/**
 * Shift rotations — repeating multi-day patterns.
 *
 * The cycle is measured in days rather than weeks so that patterns which do not
 * align to a 7-day week (four-on-four-off, common in hospitals and security)
 * are expressible at all. A step with no shift is a rest day, which is part of
 * the pattern rather than missing data.
 */

export interface ShiftRotationStep {
  day_offset: number;
  shift: Shift | null;
  is_rest_day: boolean;
}

export interface ShiftRotation {
  public_id: string;
  name: string;
  name_am: string | null;
  description: string | null;
  cycle_days: number;
  is_active: boolean;
  steps?: ShiftRotationStep[];
  assignments_count?: number;
  created_at?: string;
  updated_at?: string;
}

/** What the form sends: a shift's public_id, or null for a rest day. */
export interface ShiftRotationStepInput {
  day_offset: number;
  shift_id: string | null;
}

export interface ShiftRotationInput {
  name: string;
  name_am?: string | null;
  description?: string | null;
  cycle_days: number;
  is_active?: boolean;
  steps: ShiftRotationStepInput[];
}

export interface RotationPreviewDay {
  date: string;
  shift: {
    public_id: string;
    name: string;
    start_time: string;
    end_time: string;
  } | null;
  is_rest_day: boolean;
}

export interface RotationPreview {
  rotation: ShiftRotation;
  anchor_date: string;
  days: RotationPreviewDay[];
}

export function useShiftRotations(params?: {
  page?: number;
  per_page?: number;
}) {
  return useQuery<PaginatedResponse<ShiftRotation>>({
    queryKey: ["shift-rotations", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/shift-rotations", { params });
      return data;
    },
    // Matches useShifts: rotation patterns change rarely, so a long stale time
    // avoids refetching a definition that is effectively static.
    staleTime: 30 * 60 * 1000,
  });
}

export function useShiftRotation(publicId: string) {
  return useQuery<ShiftRotation>({
    queryKey: ["shift-rotations", publicId],
    queryFn: async () => {
      const { data } = await apiClient.get(`/shift-rotations/${publicId}`);
      return data;
    },
    enabled: !!publicId,
    staleTime: 30 * 60 * 1000,
  });
}

/**
 * The resolved day-by-day pattern. Server-side rather than computed here on
 * purpose: the modulo arithmetic (including dates before the anchor) lives in
 * one place, so the roster a user previews is the one attendance matching will
 * later apply.
 */
export function useRotationPreview(
  publicId: string,
  params: { from: string; to: string; anchor_date?: string },
  enabled = true,
) {
  return useQuery<RotationPreview>({
    queryKey: ["shift-rotations", publicId, "preview", params],
    queryFn: async () => {
      const { data } = await apiClient.get(
        `/shift-rotations/${publicId}/preview`,
        { params },
      );
      return data;
    },
    enabled: enabled && !!publicId && !!params.from && !!params.to,
    staleTime: 5 * 60 * 1000,
  });
}

export function useCreateShiftRotation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: ShiftRotationInput) => {
      const { data } = await apiClient.post("/shift-rotations", input);
      return data as ShiftRotation;
    },
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: ["shift-rotations"] }),
  });
}

export function useUpdateShiftRotation(publicId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: ShiftRotationInput) => {
      const { data } = await apiClient.put(
        `/shift-rotations/${publicId}`,
        input,
      );
      return data as ShiftRotation;
    },
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: ["shift-rotations"] }),
  });
}

export function useDeleteShiftRotation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) =>
      apiClient.delete(`/shift-rotations/${publicId}`),
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: ["shift-rotations"] }),
  });
}

export function useAssignShiftRotation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: {
      rotation_id: string;
      assignable_type: "employee" | "department" | "branch";
      assignable_id: string;
      effective_from: string;
      effective_to?: string | null;
      anchor_date?: string | null;
    }) => (await apiClient.post("/shift-rotations/assign", payload)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["shift-rotations"] });
      // The schedule is derived from assignments, so it is stale the moment one
      // is created.
      queryClient.invalidateQueries({ queryKey: ["shifts", "schedule"] });
    },
  });
}

/**
 * Builds a full set of steps for a cycle, preserving any already chosen.
 *
 * The API requires every offset it is given to fall inside the cycle and to
 * appear once, so shrinking `cycle_days` has to drop the now-unreachable tail
 * rather than leave it behind for the server to reject.
 */
export function resizeSteps(
  steps: ShiftRotationStepInput[],
  cycleDays: number,
): ShiftRotationStepInput[] {
  const byOffset = new Map(steps.map((s) => [s.day_offset, s.shift_id]));

  return Array.from({ length: Math.max(0, cycleDays) }, (_, offset) => ({
    day_offset: offset,
    shift_id: byOffset.get(offset) ?? null,
  }));
}
