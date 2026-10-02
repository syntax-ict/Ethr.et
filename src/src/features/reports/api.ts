import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";

type Schemas = components["schemas"];
type Ok<Op extends "sources" | "generate" | "savedList" | "scheduledList"> =
  operations[`report.${Op}`]["responses"][200]["content"]["application/json"];

// Shapes come from the generated contract. Where Scramble cannot see through
// an array-cast column or ReportEngine's built-up arrays, a narrow override
// says so; each was checked against ReportController and ReportEngine.

/** `ReportEngine::SOURCES`, keyed by source, each with its column list. */
export type ReportSources = Ok<"sources">["sources"];
export type ReportSourceKey = keyof ReportSources;

/**
 * The `filters` keys ReportEngine actually applies, per source; it ignores any
 * other key without error. These are not the source's columns — offering the
 * columns let a user filter on `gender` or `name` and get every row back.
 * Employees' `department_id` is left out: it takes the internal numeric id,
 * which the API never exposes. The contract does not publish this list, so it
 * mirrors `ReportEngine::query*()` and must move with it.
 */
export const REPORT_FILTERS: Record<ReportSourceKey, readonly string[]> = {
  employees: ["status"],
  attendance: ["from", "to"],
  leave: ["year"],
  payroll: [],
};

/**
 * The body of `POST /reports/generate`. Scramble reads the `array` rule on
 * `filters` as a list; ReportEngine reads it as a field → value map.
 * `format` belongs to `/reports/export`, which the page does not call.
 */
export type ReportConfig = Omit<
  Schemas["GenerateReportRequest"],
  "filters" | "format"
> & { filters?: Record<string, string> };

type GenerateContract = Ok<"generate">;

/**
 * Rows are the source's column map. `summary` is `[]` when ungrouped, else
 * per-group row counts plus, when any `*_cents` column is present, per-group
 * sums — Scramble types both maps as `string`.
 */
export type ReportResult = Omit<GenerateContract, "data" | "summary"> & {
  data: Array<Record<string, unknown>>;
  summary: {
    grouped_by?: string | null;
    groups?: Record<string, number>;
    group_sums?: Record<string, Record<string, number>>;
  };
};

/** `config` is the stored (array-cast) generate body, published as `unknown[]`. */
export type SavedReport = Omit<Ok<"savedList">["reports"][number], "config"> & {
  config: ReportConfig;
};

/**
 * `frequency` and `recipients` are columns ScheduleReportRequest constrains to
 * its enum and to email strings; Scramble sees `string` and `unknown[]`.
 */
export type ScheduledReport = Omit<
  Ok<"scheduledList">["schedules"][number],
  "frequency" | "recipients"
> & {
  frequency: Schemas["ScheduleReportRequest"]["frequency"];
  recipients: string[];
};

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
    mutationFn: async (payload: Schemas["ScheduleReportRequest"]) => {
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

// No hook for POST /reports/export, deliberately. It runs the same
// ReportEngine::generate() call as /reports/generate — same 1000-row limits —
// so the rows the Reports page already holds from its preview are exactly the
// rows an export would return, and the page builds the CSV from them through
// csvCell, which neutralises spreadsheet formulas. ReportEngine::toCsv() does
// not. The server route stays for API clients and the PDF format.
