import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

export interface ReportSource {
  label: string;
  fields: string[];
}

export type ReportSources = Record<string, ReportSource>;

export interface ReportConfig {
  source: string;
  columns?: string[];
  filters?: Record<string, string>;
  group_by?: string;
  sort_by?: string;
  sort_dir?: "asc" | "desc";
}

export interface ReportResult {
  source: string;
  total: number;
  data: Array<Record<string, unknown>>;
  summary: { grouped_by?: string; groups?: Record<string, number> };
}

export interface SavedReport {
  public_id: string;
  name: string;
  config: ReportConfig;
  created_at: string;
}

export interface ScheduledReport {
  public_id: string;
  report_name: string;
  frequency: "daily" | "weekly" | "monthly";
  recipients: string[];
  next_run_at: string;
  last_run_at: string | null;
}

export function useReportSources() {
  return useQuery<{ sources: ReportSources }>({
    queryKey: ["reports", "sources"],
    queryFn: async () => {
      const { data } = await apiClient.get("/reports/sources");
      return data;
    },
    staleTime: 10 * 60 * 1000,
  });
}

export function useGenerateReport() {
  return useMutation<ReportResult, unknown, ReportConfig>({
    mutationFn: async (config) => {
      const { data } = await apiClient.post("/reports/generate", config);
      return data;
    },
  });
}

export function useSavedReports() {
  return useQuery<{ reports: SavedReport[] }>({
    queryKey: ["reports", "saved"],
    queryFn: async () => {
      const { data } = await apiClient.get("/reports/saved");
      return data;
    },
  });
}

export function useSaveReport() {
  const queryClient = useQueryClient();

  return useMutation<
    SavedReport,
    unknown,
    { name: string; config: ReportConfig }
  >({
    mutationFn: async (payload) => {
      const { data } = await apiClient.post("/reports/save", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["reports", "saved"] });
    },
  });
}

export function useDeleteSavedReport() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) => {
      await apiClient.delete(`/reports/saved/${publicId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["reports", "saved"] });
      queryClient.invalidateQueries({ queryKey: ["reports", "scheduled"] });
    },
  });
}

export function useScheduledReports() {
  return useQuery<{ schedules: ScheduledReport[] }>({
    queryKey: ["reports", "scheduled"],
    queryFn: async () => {
      const { data } = await apiClient.get("/reports/scheduled");
      return data;
    },
  });
}

export function useScheduleReport() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: {
      saved_report_public_id: string;
      frequency: "daily" | "weekly" | "monthly";
      recipients: string[];
    }) => {
      const { data } = await apiClient.post("/reports/schedule", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["reports", "scheduled"] });
    },
  });
}

export function useDeleteScheduledReport() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) => {
      await apiClient.delete(`/reports/scheduled/${publicId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["reports", "scheduled"] });
    },
  });
}

export function useExportReport() {
  return useMutation<
    Blob,
    unknown,
    { config: ReportConfig; format: "csv" | "pdf" }
  >({
    mutationFn: async ({ config, format }) => {
      const { data } = await apiClient.post(
        `/reports/export`,
        { ...config, format },
        { responseType: "blob" },
      );
      return data;
    },
  });
}
