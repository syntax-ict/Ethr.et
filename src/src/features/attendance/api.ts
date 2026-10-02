import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { fetchAllPages } from "@/api/fetch-all-pages";
import type { components, operations } from "@/api/generated";
import type { PaginatedResponse } from "@/api/types";

// ── Types ─────────────────────────────────────────────────────────────────────
// Shapes come from the generated contract, so a renamed resource field fails
// tsc here instead of rendering blank. The hand-written ones they replace had
// drifted: the corrections page read `date`, `corrected_check_in` and
// `original_check_in`, none of which AttendanceCorrectionResource has ever
// returned, and posted fields StoreCorrectionRequest does not accept.

export type AttendanceRecord =
  components["schemas"]["AttendanceRecordResource"];
export type AttendanceCorrection =
  components["schemas"]["AttendanceCorrectionResource"];
export type AttendanceConflict =
  components["schemas"]["AttendanceConflictResource"];
export type KioskSession = components["schemas"]["KioskSessionResource"];
export type EmployeeOption = components["schemas"]["EmployeeResource"];
export type ShiftOption = components["schemas"]["ShiftResource"];

export type ManualAttendancePayload =
  components["schemas"]["ManualAttendanceRequest"];
export type SubmitCorrectionPayload =
  components["schemas"]["StoreCorrectionRequest"];
export type ResolveConflictPayload =
  components["schemas"]["ResolveConflictRequest"];
export type ConflictResolution = ResolveConflictPayload["resolution"];
export type RegisterKioskPayload =
  components["schemas"]["RegisterKioskRequest"];
export type AttendanceSettingsUpdate =
  components["schemas"]["UpdateAttendanceSettingRequest"];
export type ImportCommitPayload =
  components["schemas"]["AttendanceImportCommitRequest"];
export type ImportCommitResult =
  operations["attendanceImport.commit"]["responses"][201]["content"]["application/json"];
export type ImportTemplate =
  operations["attendanceImport.template"]["responses"][200]["content"]["application/json"];
export type QrCode =
  operations["qrAttendance.generate"]["responses"][200]["content"]["application/json"];
export type KioskToken =
  operations["kioskSession.regenerateToken"]["responses"][200]["content"]["application/json"];

export type AttendanceIntelligence =
  operations["attendanceIntelligence.dashboard"]["responses"][200]["content"]["application/json"];

export type OvertimeSummary =
  operations["attendanceIntelligence.overtime"]["responses"][200]["content"]["application/json"];

export type CorrectionPayrollImpact =
  operations["attendanceCorrection.payrollImpact"]["responses"][200]["content"]["application/json"];

/**
 * `GET /attendance/settings`. `enabled_methods` is an `array`-cast column,
 * which Scramble can only publish as `unknown[]`; the request that writes it
 * admits nothing but these method names.
 */
export type AttendanceSettings = Omit<
  components["schemas"]["AttendanceSettingResource"],
  "enabled_methods"
> & {
  enabled_methods: NonNullable<AttendanceSettingsUpdate["enabled_methods"]>;
};

/**
 * The contract publishes the preview rows as `unknown[][]`. Mirrors
 * AttendanceImporter::preview(): each row is the CSV line keyed by its header
 * plus `line`, `errors` and `valid`. `check_out_time` is absent when the file
 * has no such column.
 */
export interface ImportPreviewRow {
  employee_code: string;
  date: string;
  check_in_time: string;
  check_out_time?: string;
  line: number;
  valid: boolean;
  errors: string[];
}

export interface ImportPreview {
  rows: ImportPreviewRow[];
  valid: number;
  invalid: number;
  errors: string[];
}

/** A punch endpoint's record, plus whether the request was a replay. */
export type PunchResult = components["schemas"]["AttendancePunchResource"];

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

/** `GET /attendance/my` reads unprefixed date bounds, unlike the HR list. */
export interface MyAttendanceFilters {
  page?: number;
  per_page?: number;
  date_from?: string;
  date_to?: string;
}

/**
 * Every attendance query sits under ["attendance"], so one invalidation after a
 * punch, a correction or an import refreshes every list on screen.
 */
const keys = {
  all: ["attendance"] as const,
  list: (params?: AttendanceFilters) => ["attendance", "list", params] as const,
  my: (params?: MyAttendanceFilters) => ["attendance", "my", params] as const,
  team: (params: { date: string; page: number }) =>
    ["attendance", "team", params] as const,
  corrections: ["attendance", "corrections"] as const,
  conflicts: ["attendance", "conflicts"] as const,
  kiosks: ["attendance", "kiosks"] as const,
  settings: ["attendance", "settings"] as const,
};

function useAttendanceMutation<TVars, TData>(
  mutationFn: (vars: TVars) => Promise<TData>,
  invalidate: readonly (readonly string[])[] = [keys.all],
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => {
      for (const queryKey of invalidate) {
        queryClient.invalidateQueries({ queryKey });
      }
    },
  });
}

// ── Records ───────────────────────────────────────────────────────────────────

/** The caller's own records (`attendance.viewOwn`, granted to everyone). */
export function useMyAttendance(
  params?: MyAttendanceFilters,
  options?: { enabled?: boolean },
) {
  return useQuery<PaginatedResponse<AttendanceRecord>>({
    queryKey: keys.my(params),
    queryFn: async () =>
      (await apiClient.get("/attendance/my", { params })).data,
    staleTime: 60 * 1000,
    enabled: options?.enabled ?? true,
  });
}

/** Every accessible employee's records (`attendance.viewAll`). */
export function useAttendanceList(
  params?: AttendanceFilters,
  options?: { enabled?: boolean },
) {
  return useQuery<PaginatedResponse<AttendanceRecord>>({
    queryKey: keys.list(params),
    queryFn: async () => (await apiClient.get("/attendance", { params })).data,
    staleTime: 60 * 1000,
    enabled: options?.enabled ?? true,
  });
}

/** One day of the caller's team (`attendance.viewTeam`). */
export function useTeamAttendance(params: {
  date: string;
  page: number;
  per_page?: number;
}) {
  return useQuery<PaginatedResponse<AttendanceRecord>>({
    queryKey: keys.team({ date: params.date, page: params.page }),
    queryFn: async () =>
      (await apiClient.get("/attendance/team", { params })).data,
  });
}

// ── Check In / Out ────────────────────────────────────────────────────────────

export function useCheckIn() {
  return useAttendanceMutation(
    async (payload: {
      idempotency_key: string;
      source?: string;
    }): Promise<PunchResult> =>
      (
        await apiClient.post("/attendance/check-in", payload, {
          headers: { "Idempotency-Key": payload.idempotency_key },
        })
      ).data,
    [keys.all, ["dashboard"]],
  );
}

export function useCheckOut() {
  return useAttendanceMutation(
    async (payload: { idempotency_key: string }): Promise<PunchResult> =>
      (
        await apiClient.post("/attendance/check-out", payload, {
          headers: { "Idempotency-Key": payload.idempotency_key },
        })
      ).data,
    [keys.all, ["dashboard"]],
  );
}

/** Check in or out from the mobile page, with location and an optional selfie. */
export function useMobilePunch() {
  return useAttendanceMutation(
    async (vars: {
      type: "check_in" | "check_out";
      idempotency_key: string;
      latitude: number;
      longitude: number;
      photo?: string;
    }): Promise<PunchResult> => {
      const { type, ...payload } = vars;
      const path =
        type === "check_in"
          ? "/attendance/mobile/check-in"
          : "/attendance/mobile/check-out";
      return (await apiClient.post(path, payload)).data;
    },
    [keys.all, ["dashboard"]],
  );
}

/** Check in or out by presenting a QR token generated for a branch. */
export function useScanQr() {
  return useAttendanceMutation(
    async (payload: {
      qr_token: string;
      type: "check_in" | "check_out";
      idempotency_key: string;
    }): Promise<PunchResult> =>
      (await apiClient.post("/attendance/qr", payload)).data,
    [keys.all, ["dashboard"]],
  );
}

export function useManualAttendance() {
  return useAttendanceMutation(
    async (payload: ManualAttendancePayload): Promise<PunchResult> =>
      (
        await apiClient.post("/attendance/manual", payload, {
          headers: { "Idempotency-Key": payload.idempotency_key },
        })
      ).data,
  );
}

// ── Pickers ───────────────────────────────────────────────────────────────────

/**
 * Every employee the caller can see, for the manual-entry picker. The page used
 * to take one page of 100 as the whole list, so a tenant's 101st employee could
 * not have attendance entered for them.
 */
export function useEmployeeOptions(options?: { enabled?: boolean }) {
  return useQuery<EmployeeOption[]>({
    queryKey: ["employees", "options"],
    queryFn: () => fetchAllPages<EmployeeOption>("/employees"),
    staleTime: 5 * 60 * 1000,
    enabled: options?.enabled ?? true,
  });
}

/** Every shift, for the QR generator's shift picker (25 per page otherwise). */
export function useShiftOptions() {
  return useQuery<ShiftOption[]>({
    queryKey: ["shifts", "options"],
    queryFn: () => fetchAllPages<ShiftOption>("/shifts"),
    staleTime: 5 * 60 * 1000,
  });
}

// ── Corrections ───────────────────────────────────────────────────────────────

/** Every correction in the tenant (`correction.viewAll`). */
export function useCorrections(params?: {
  page?: number;
  "filter[status]"?: string;
}) {
  return useQuery<PaginatedResponse<AttendanceCorrection>>({
    queryKey: [...keys.corrections, "all", params],
    queryFn: async () =>
      (await apiClient.get("/attendance/corrections", { params })).data,
    staleTime: 60 * 1000,
  });
}

/** The caller's own corrections, in every state (`correction.viewOwn`). */
export function useMyCorrections(params?: {
  page?: number;
  "filter[status]"?: string;
}) {
  return useQuery<PaginatedResponse<AttendanceCorrection>>({
    queryKey: [...keys.corrections, "my", params],
    queryFn: async () =>
      (await apiClient.get("/attendance/corrections/my", { params })).data,
    staleTime: 60 * 1000,
  });
}

/**
 * Corrections awaiting a decision (`correction.viewPending`) — limited
 * server-side to the employees the caller may decide for, never their own.
 */
export function usePendingCorrections(params?: { page?: number }) {
  return useQuery<PaginatedResponse<AttendanceCorrection>>({
    queryKey: [...keys.corrections, "pending", params],
    queryFn: async () =>
      (await apiClient.get("/attendance/corrections/pending", { params })).data,
    staleTime: 60 * 1000,
  });
}

export function useCorrectionPayrollImpact(publicId: string) {
  return useQuery<CorrectionPayrollImpact>({
    queryKey: [...keys.corrections, "impact", publicId],
    queryFn: async () =>
      (
        await apiClient.get(
          `/attendance/corrections/${publicId}/payroll-impact`,
        )
      ).data,
    staleTime: 60 * 1000,
    enabled: !!publicId,
  });
}

export function useSubmitCorrection() {
  return useAttendanceMutation(
    async (payload: SubmitCorrectionPayload): Promise<AttendanceCorrection> =>
      (await apiClient.post("/attendance/corrections", payload)).data,
    [keys.corrections],
  );
}

/** Approving rewrites the record's punches, so every attendance list refreshes. */
export function useApproveCorrection() {
  return useAttendanceMutation(
    async (publicId: string): Promise<AttendanceCorrection> =>
      (await apiClient.put(`/attendance/corrections/${publicId}/approve`)).data,
  );
}

export function useRejectCorrection() {
  return useAttendanceMutation(
    async ({
      publicId,
      reason,
    }: {
      publicId: string;
      reason: string;
    }): Promise<AttendanceCorrection> =>
      (
        await apiClient.put(`/attendance/corrections/${publicId}/reject`, {
          reason,
        })
      ).data,
    [keys.corrections],
  );
}

// ── Conflicts ─────────────────────────────────────────────────────────────────

export function useAttendanceConflicts(params: {
  pendingOnly: boolean;
  page: number;
}) {
  return useQuery<PaginatedResponse<AttendanceConflict>>({
    queryKey: [...keys.conflicts, params],
    queryFn: async () => {
      const query: Record<string, string | number> = { page: params.page };
      if (params.pendingOnly) query["filter[resolution]"] = "pending";
      return (await apiClient.get("/attendance/conflicts", { params: query }))
        .data;
    },
  });
}

/** Keeping one record voids the other, so every attendance list refreshes. */
export function useResolveConflict() {
  return useAttendanceMutation(
    async (vars: {
      publicId: string;
      payload: ResolveConflictPayload;
    }): Promise<AttendanceConflict> =>
      (
        await apiClient.put(
          `/attendance/conflicts/${vars.publicId}/resolve`,
          vars.payload,
        )
      ).data,
  );
}

// ── CSV import ────────────────────────────────────────────────────────────────

/** A POST, so a mutation: the template is fetched on demand, not on mount. */
export function useImportTemplate() {
  return useMutation({
    mutationFn: async (): Promise<ImportTemplate> =>
      (await apiClient.post("/attendance/import/template")).data,
  });
}

/** Parse and validate a file without writing anything. */
export function usePreviewImport() {
  return useMutation({
    mutationFn: async (file: File): Promise<ImportPreview> => {
      const formData = new FormData();
      formData.append("file", file);
      return (
        await apiClient.post("/attendance/import/preview", formData, {
          headers: { "Content-Type": "multipart/form-data" },
        })
      ).data;
    },
  });
}

export function useCommitImport() {
  return useAttendanceMutation(
    async (payload: ImportCommitPayload): Promise<ImportCommitResult> =>
      (await apiClient.post("/attendance/import/commit", payload)).data,
  );
}

// ── Intelligence / Overtime ───────────────────────────────────────────────────

export function useAttendanceIntelligence(date?: string) {
  return useQuery<AttendanceIntelligence>({
    queryKey: ["attendance", "intelligence", date],
    queryFn: async () =>
      (
        await apiClient.get("/attendance/intelligence", {
          params: date ? { date } : undefined,
        })
      ).data,
    staleTime: 2 * 60 * 1000,
  });
}

export function useAttendanceOvertime(period: "weekly" | "monthly") {
  return useQuery<OvertimeSummary>({
    queryKey: ["attendance", "overtime", period],
    queryFn: async () =>
      (await apiClient.get("/attendance/overtime", { params: { period } }))
        .data,
    staleTime: 2 * 60 * 1000,
  });
}

// ── QR generator ──────────────────────────────────────────────────────────────

/**
 * A GET, but each call mints a new token, so it is a mutation the page fires
 * on demand (and on expiry) rather than a query that refetches on focus.
 */
export function useGenerateQr() {
  return useMutation({
    mutationFn: async (params: {
      branch_public_id: string;
      shift_public_id?: string;
      expiry_minutes: number;
    }): Promise<QrCode> =>
      (await apiClient.get("/attendance/qr/generate", { params })).data,
  });
}

// ── Kiosk sessions ────────────────────────────────────────────────────────────

/** `KioskSessionController::index` pages by 25 and ignores `per_page`. */
export function useKioskSessions(params: { page: number }) {
  return useQuery<PaginatedResponse<KioskSession>>({
    queryKey: [...keys.kiosks, params],
    queryFn: async () =>
      (await apiClient.get("/kiosk-sessions", { params })).data,
  });
}

/** The response carries the session token, once; the page must show it. */
export function useRegisterKiosk() {
  return useAttendanceMutation(
    async (payload: RegisterKioskPayload): Promise<KioskSession> =>
      (await apiClient.post("/kiosk-sessions", payload)).data,
    [keys.kiosks],
  );
}

export function useSetKioskActive() {
  return useAttendanceMutation(
    async (vars: { publicId: string; active: boolean }) =>
      (
        await apiClient.post(
          `/kiosk-sessions/${vars.publicId}/${vars.active ? "activate" : "deactivate"}`,
        )
      ).data as { status: string },
    [keys.kiosks],
  );
}

export function useRegenerateKioskToken() {
  return useAttendanceMutation(
    async (publicId: string): Promise<KioskToken> =>
      (await apiClient.post(`/kiosk-sessions/${publicId}/regenerate-token`))
        .data,
    [keys.kiosks],
  );
}

export function useDeleteKiosk() {
  return useAttendanceMutation(
    async (publicId: string): Promise<void> => {
      await apiClient.delete(`/kiosk-sessions/${publicId}`);
    },
    [keys.kiosks],
  );
}

// ── Settings ──────────────────────────────────────────────────────────────────

export function useAttendanceSettings() {
  return useQuery<AttendanceSettings>({
    queryKey: keys.settings,
    queryFn: async () => (await apiClient.get("/attendance/settings")).data,
  });
}

export function useUpdateAttendanceSettings() {
  return useAttendanceMutation(
    async (payload: AttendanceSettingsUpdate): Promise<AttendanceSettings> =>
      (await apiClient.put("/attendance/settings", payload)).data,
    [keys.settings],
  );
}
