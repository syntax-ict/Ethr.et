import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { PaginatedResponse } from "@/api/types";

export interface Device {
  public_id: string;
  name: string;
  location_description: string | null;
  serial_number: string | null;
  adapter_type: string;
  status: "online" | "offline" | "unknown";
  auto_sync: boolean;
  sync_interval_minutes: number;
  webhook_token?: string | null;
  webhook_url?: string | null;
  last_sync_at: string | null;
  branch_public_id: string | null;
  branch?: { public_id: string; name: string } | null;
  attendance_records_count?: number;
  sync_logs_count?: number;
  created_at: string;
  updated_at: string;
}

export interface DeviceSyncLog {
  public_id?: string;
  status: string;
  records_pulled: number;
  error_message: string | null;
  synced_at: string;
}

export interface CreateDevicePayload {
  name: string;
  location_description?: string;
  serial_number?: string;
  adapter_type: string;
  branch_public_id?: string;
  auto_sync?: boolean;
  sync_interval_minutes?: number;
}

// ── List + Detail ─────────────────────────────────────────────────────────────

export function useDevices(params?: { page?: number; per_page?: number }) {
  return useQuery<PaginatedResponse<Device>>({
    queryKey: ["devices", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/devices", { params });
      return data;
    },
    staleTime: 2 * 60 * 1000,
  });
}

export function useDevice(publicId: string) {
  return useQuery<Device>({
    queryKey: ["devices", publicId],
    queryFn: async () => {
      const { data } = await apiClient.get(`/devices/${publicId}`);
      return data;
    },
    enabled: !!publicId,
    staleTime: 2 * 60 * 1000,
  });
}

export function useDeviceDashboard() {
  return useQuery({
    queryKey: ["devices", "dashboard"],
    queryFn: async () => {
      const { data } = await apiClient.get("/devices/dashboard");
      return data;
    },
    staleTime: 2 * 60 * 1000,
  });
}

export function useDeviceStatus(publicId: string) {
  return useQuery({
    queryKey: ["devices", publicId, "status"],
    queryFn: async () => {
      const { data } = await apiClient.get(`/devices/${publicId}/status`);
      return data;
    },
    enabled: !!publicId,
    refetchInterval: 30000,
  });
}

export function useDeviceSyncLogs(
  publicId: string,
  params?: { page?: number },
) {
  return useQuery<PaginatedResponse<DeviceSyncLog>>({
    queryKey: ["devices", publicId, "sync-logs", params],
    queryFn: async () => {
      const { data } = await apiClient.get(`/devices/${publicId}/sync-logs`, {
        params,
      });
      return data;
    },
    enabled: !!publicId,
  });
}

// ── CRUD ──────────────────────────────────────────────────────────────────────

export function useCreateDevice() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: CreateDevicePayload) => {
      const { data } = await apiClient.post("/devices", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["devices"] });
    },
  });
}

export function useUpdateDevice(publicId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: Partial<CreateDevicePayload>) => {
      const { data } = await apiClient.put(`/devices/${publicId}`, payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["devices"] });
    },
  });
}

export function useDeleteDevice() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) => {
      await apiClient.delete(`/devices/${publicId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["devices"] });
    },
  });
}

// ── Actions ───────────────────────────────────────────────────────────────────

export function usePullDeviceEvents() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) => {
      const { data } = await apiClient.post(`/devices/${publicId}/pull`);
      return data;
    },
    onSuccess: (_data, publicId) => {
      queryClient.invalidateQueries({ queryKey: ["devices", publicId] });
      queryClient.invalidateQueries({ queryKey: ["attendance"] });
    },
  });
}

// ── Workforce discovery & history backfill ───────────────────────────────────

export type MatchOutcome = "matched" | "probable" | "ambiguous" | "new";

export interface DeviceEnrollment {
  device_user_id: string;
  name: string | null;
  card_number: string | null;
  department: string | null;
  fingerprint_count: number | null;
  face_registered: boolean | null;
  match: {
    outcome: MatchOutcome;
    employee_public_id: string | null;
    confidence: number;
  };
}

export interface DeviceEnrollmentsResponse {
  device: { public_id: string; name: string; adapter_type: string };
  enrollments: DeviceEnrollment[];
  summary: {
    total: number;
    matched: number;
    probable: number;
    ambiguous: number;
    new: number;
  };
}

/** Read the people enrolled on a device, each with a suggested employee match. */
export function useDeviceEnrollments(publicId: string | null) {
  return useQuery<DeviceEnrollmentsResponse>({
    queryKey: ["devices", publicId, "enrollments"],
    queryFn: async () =>
      (await apiClient.get(`/devices/${publicId}/enrollments`)).data,
    enabled: !!publicId,
  });
}

export type HistoryWindow = "last_30" | "last_90" | "from_date" | "full";

/** One-off attendance backfill; punches are dated at their real event time. */
export function useImportDeviceHistory() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (vars: {
      publicId: string;
      window: HistoryWindow;
      from_date?: string;
    }) => {
      const { data } = await apiClient.post(
        `/devices/${vars.publicId}/import-history`,
        { window: vars.window, from_date: vars.from_date },
      );
      return data;
    },
    onSuccess: (_data, vars) => {
      queryClient.invalidateQueries({ queryKey: ["devices", vars.publicId] });
      queryClient.invalidateQueries({ queryKey: ["attendance"] });
    },
  });
}

export function useRegenerateDeviceToken() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) => {
      const { data } = await apiClient.post(
        `/devices/${publicId}/regenerate-token`,
      );
      return data;
    },
    onSuccess: (_data, publicId) => {
      queryClient.invalidateQueries({ queryKey: ["devices", publicId] });
    },
  });
}
