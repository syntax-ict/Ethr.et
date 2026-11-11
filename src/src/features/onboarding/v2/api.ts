import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type {
  AccessPolicy,
  ApplyConfigurationResult,
  CommitResult,
  ConfigurationPlan,
  ConfigurationProfile,
  CreateLoginsResult,
  GoLiveResult,
  Industry,
  IndustryCatalogResponse,
  LoginIdentifier,
  MigrationBatch,
  MigrationLogins,
  PeopleRead,
  ReadinessReport,
  StagingAction,
  StagingRow,
} from "./types";

/** The headcount bands onboarding asks for (`TenantLanding::STAFF_BANDS`). */
export const STAFF_BANDS = [
  "1-10",
  "11-50",
  "51-200",
  "201-500",
  "501-1000",
  "1000+",
] as const;
export type StaffBand = (typeof STAFF_BANDS)[number];

// ── Smart configuration ──────────────────────────────────────────

// One request serves the industry picker and the two profile pickers.
function useIndustryCatalog<T>(select: (data: IndustryCatalogResponse) => T) {
  return useQuery<IndustryCatalogResponse, Error, T>({
    queryKey: ["onboarding", "industries"],
    queryFn: async () => (await apiClient.get("/onboarding/industries")).data,
    staleTime: 60 * 60 * 1000,
    select,
  });
}

const selectIndustries = (data: IndustryCatalogResponse): Industry[] =>
  data.data;
const selectProfiles = (
  data: IndustryCatalogResponse,
): { workforce: ConfigurationProfile[]; policy: ConfigurationProfile[] } => ({
  workforce: data.workforce_profiles ?? [],
  policy: data.policy_profiles ?? [],
});

export function useIndustries() {
  return useIndustryCatalog(selectIndustries);
}

export function useConfigurationProfiles() {
  return useIndustryCatalog(selectProfiles);
}

export function usePreviewConfiguration() {
  return useMutation<
    ConfigurationPlan,
    unknown,
    {
      industry: string;
      employee_count?: number;
      staff_band?: StaffBand;
      region?: string;
      workforce_profile?: string;
      policy_profile?: string;
      organization_type?: string;
    }
  >({
    mutationFn: async (payload) =>
      (await apiClient.post("/onboarding/configuration/preview", payload)).data,
  });
}

export function useApplyConfiguration() {
  const qc = useQueryClient();
  return useMutation<
    ApplyConfigurationResult,
    unknown,
    {
      industry: string;
      plan: Record<string, unknown>;
      save?: boolean;
      workforce_profile?: string;
      policy_profile?: string;
      organization_type?: string;
      staff_band?: StaffBand;
      pay_period?: "monthly";
      calendar?: "ethiopian" | "gregorian";
    }
  >({
    mutationFn: async (payload) =>
      (await apiClient.post("/onboarding/configuration/apply", payload)).data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["onboarding"] });
    },
  });
}

// ── Access & identity ────────────────────────────────────────────

export function useAccessPolicy() {
  return useQuery<AccessPolicy>({
    queryKey: ["onboarding", "access"],
    queryFn: async () => (await apiClient.get("/onboarding/access")).data,
  });
}

export function useUpdateAccessPolicy() {
  const qc = useQueryClient();
  return useMutation<
    AccessPolicy,
    unknown,
    {
      login_identifiers: LoginIdentifier[];
      role_defaults?: Record<string, string>;
    }
  >({
    mutationFn: async (payload) =>
      (await apiClient.put("/onboarding/access", payload)).data,
    onSuccess: (data) => {
      qc.setQueryData(["onboarding", "access"], data);
    },
  });
}

// ── Readiness ────────────────────────────────────────────────────

export function useReadiness() {
  return useQuery<ReadinessReport>({
    queryKey: ["onboarding", "readiness"],
    queryFn: async () => (await apiClient.get("/onboarding/readiness")).data,
  });
}

export function useGoLive() {
  const qc = useQueryClient();
  return useMutation<GoLiveResult, unknown, void>({
    mutationFn: async () => (await apiClient.post("/onboarding/go-live")).data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["onboarding"] });
      qc.invalidateQueries({ queryKey: ["dashboard"] });
    },
  });
}

// ── Migration ────────────────────────────────────────────────────

export function useMigrationBatch(batchId: string | null) {
  return useQuery<MigrationBatch>({
    queryKey: ["onboarding", "migration", batchId],
    queryFn: async () =>
      (await apiClient.get(`/onboarding/migration/batches/${batchId}`)).data,
    enabled: !!batchId,
  });
}

export function useStageFromDevice() {
  return useMutation<MigrationBatch, unknown, string>({
    mutationFn: async (devicePublicId) =>
      (await apiClient.post(`/onboarding/migration/devices/${devicePublicId}`))
        .data,
  });
}

/**
 * What the server makes of a list, before anything is staged: who is ready,
 * which columns were read, and each line that cannot be staged. Asked again
 * whenever the list or the calendar changes; the last answer stays on screen
 * meanwhile so the count does not blink.
 */
export function useReadPeople(
  grid: string[][],
  calendar: "ethiopian" | "gregorian",
  enabled = true,
) {
  return useQuery<PeopleRead>({
    queryKey: ["onboarding", "migration", "read", calendar, grid],
    queryFn: async () =>
      (await apiClient.post("/onboarding/migration/read", { grid, calendar }))
        .data,
    enabled: enabled && grid.some((line) => line.length > 0),
    placeholderData: keepPreviousData,
    staleTime: 60_000,
    retry: false,
  });
}

/**
 * Stage a list for review: as lines of cells (`grid`), which the server reads
 * as it reads every list, or as people already keyed by field (`rows`).
 */
export function useStageFromRows() {
  return useMutation<
    MigrationBatch,
    unknown,
    | {
        grid: string[][];
        calendar: "ethiopian" | "gregorian";
        source_type?: string;
      }
    | { rows: Record<string, unknown>[]; source_type?: string }
  >({
    mutationFn: async (payload) =>
      (await apiClient.post("/onboarding/migration/rows", payload)).data,
  });
}

export function useSetRowAction() {
  return useMutation<
    StagingRow,
    unknown,
    {
      rowId: string;
      action: StagingAction;
      employeePublicId?: string;
      /** The differences to write to the matched employee; omit to leave. */
      accept?: string[];
    }
  >({
    mutationFn: async ({ rowId, action, employeePublicId, accept }) =>
      (
        await apiClient.patch(`/onboarding/migration/rows/${rowId}`, {
          action,
          employee_public_id: employeePublicId,
          accept,
        })
      ).data,
  });
}

/** Slices in a row that may pass without the list getting shorter. */
const STALLED_AFTER = 3;

/** A commit that kept answering without the list getting shorter. */
export class CommitStalled extends Error {
  constructor() {
    super("The list stopped moving.");
    this.name = "CommitStalled";
  }
}

/**
 * Add a staged list. The server works for a few seconds per request and
 * answers `committing` with how many rows are left, so the request is sent
 * again until it answers `committed`. Nothing is added twice: each request
 * starts after the last row the one before it finished.
 */
export async function commitMigration(
  batchId: string,
  onProgress?: (result: CommitResult) => void,
): Promise<CommitResult> {
  let left = Number.POSITIVE_INFINITY;
  let stalled = 0;

  for (;;) {
    const result: CommitResult = (
      await apiClient.post(`/onboarding/migration/batches/${batchId}/commit`)
    ).data;

    if (result.status !== "committing") return result;

    onProgress?.(result);

    // A list that is not getting shorter would loop for ever.
    stalled = result.remaining < left ? 0 : stalled + 1;
    left = result.remaining;
    if (stalled >= STALLED_AFTER) {
      throw new CommitStalled();
    }
  }
}

export function useCommitMigration(
  onProgress?: (result: CommitResult) => void,
) {
  const qc = useQueryClient();
  return useMutation<CommitResult, unknown, string>({
    mutationFn: (batchId) => commitMigration(batchId, onProgress),
    // Settled, not only succeeded: a list that stopped part of the way has
    // still written rows, and the table has to show which.
    onSettled: (_data, _error, batchId) => {
      qc.invalidateQueries({ queryKey: ["onboarding", "migration", batchId] });
    },
  });
}

/** Who among a committed batch's people cannot sign in yet. */
export function useMigrationLogins(batchId: string | null) {
  return useQuery<MigrationLogins>({
    queryKey: ["onboarding", "migration", batchId, "logins"],
    queryFn: async () =>
      (await apiClient.get(`/onboarding/migration/batches/${batchId}/logins`))
        .data,
    enabled: !!batchId,
  });
}

/** A login for each employee, and one queued batch of invitations. */
export function useCreateEmployeeLogins() {
  const qc = useQueryClient();
  return useMutation<CreateLoginsResult, unknown, string[]>({
    mutationFn: async (employeeIds) =>
      (await apiClient.post("/employees/logins", { employee_ids: employeeIds }))
        .data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["onboarding", "migration"] });
      qc.invalidateQueries({ queryKey: ["users"] });
    },
  });
}
