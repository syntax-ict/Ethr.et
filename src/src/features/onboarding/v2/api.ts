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
  ArrivalStatus,
  ColumnMatch,
  CommitResult,
  ConfigurationPlan,
  ConfigurationProfile,
  CreateLoginsResult,
  GoLiveResult,
  ImportSource,
  Industry,
  IndustryCatalogResponse,
  ListColumns,
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
  columns: ListColumns = {},
) {
  const named = listColumns(columns);
  return useQuery<PeopleRead>({
    queryKey: ["onboarding", "migration", "read", calendar, grid, named],
    queryFn: async () =>
      (
        await apiClient.post("/onboarding/migration/read", {
          grid,
          calendar,
          ...named,
        })
      ).data,
    enabled: enabled && grid.some((line) => line.length > 0),
    placeholderData: keepPreviousData,
    staleTime: 60_000,
    retry: false,
  });
}

/** Only what was said: a list that names no source and no columns sends neither. */
export function listColumns(columns: ListColumns): ListColumns {
  return {
    ...(columns.source ? { source: columns.source } : {}),
    ...(columns.mapping ? { mapping: columns.mapping } : {}),
  };
}

const SOURCES_KEY = ["onboarding", "migration", "sources"] as const;

/** The systems the organisation has brought lists from, and their column names. */
export function useImportSources(enabled = true) {
  return useQuery<ImportSource[]>({
    queryKey: SOURCES_KEY,
    queryFn: async () =>
      (await apiClient.get("/onboarding/migration/sources")).data.data,
    enabled,
    staleTime: 60_000,
  });
}

/**
 * Save how a source names its columns: a new source by name, or a saved one
 * (`public_id`) matched again.
 */
export function useSaveImportSource() {
  const qc = useQueryClient();
  return useMutation<
    ImportSource,
    unknown,
    {
      public_id?: string;
      name?: string;
      mapping: ColumnMatch[];
      calendar?: "ethiopian" | "gregorian";
    }
  >({
    mutationFn: async ({ public_id, ...payload }) =>
      public_id
        ? (
            await apiClient.put(
              `/onboarding/migration/sources/${public_id}`,
              payload,
            )
          ).data
        : (await apiClient.post("/onboarding/migration/sources", payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: SOURCES_KEY }),
  });
}

export function useDeleteImportSource() {
  const qc = useQueryClient();
  return useMutation<void, unknown, string>({
    mutationFn: async (publicId) => {
      await apiClient.delete(`/onboarding/migration/sources/${publicId}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: SOURCES_KEY }),
  });
}

/** Where a list that is still being matched stands. */
export interface StagingProgress {
  public_id: string;
  status: "staging" | "reviewing";
  /** People matched so far. */
  staged: number;
  /** People still to match. The request is sent again until it is 0. */
  remaining: number;
}

/** Slices in a row that may pass without the list getting shorter. */
const STALLED_AFTER = 3;

/** A matching or a commit that kept answering without the list getting shorter. */
export class StagingStalled extends Error {
  constructor() {
    super("The list stopped moving.");
    this.name = "StagingStalled";
  }
}

/**
 * Matching a list stopped after its people were stored. The list is kept, so
 * `batchId` is enough to carry on; `cause` is what stopped it.
 */
export class StagingInterrupted extends Error {
  constructor(
    readonly batchId: string,
    readonly cause: unknown,
    readonly progress: StagingProgress | null,
  ) {
    super("Matching the list stopped before it finished.");
    this.name = "StagingInterrupted";
  }
}

/**
 * Match a stored list's people. The server works for a few seconds per
 * request and answers `staging` with how many are left, so the request is
 * sent again until it answers `reviewing`; then the list is read once.
 * Matching a person twice writes the same answer twice, so carrying on after
 * a failure is safe.
 */
export async function carryOnStaging(
  first: StagingProgress,
  onProgress?: (progress: StagingProgress) => void,
): Promise<MigrationBatch> {
  const batchId = first.public_id;
  let progress = first;
  let left = Number.POSITIVE_INFINITY;
  let stalled = 0;

  try {
    while (progress.status === "staging") {
      onProgress?.(progress);

      // A list that is not getting shorter would loop for ever.
      stalled = progress.remaining < left ? 0 : stalled + 1;
      left = progress.remaining;
      if (stalled >= STALLED_AFTER) {
        throw new StagingStalled();
      }

      progress = (
        await apiClient.post(`/onboarding/migration/batches/${batchId}/stage`)
      ).data;
    }

    return (await apiClient.get(`/onboarding/migration/batches/${batchId}`))
      .data;
  } catch (err) {
    throw new StagingInterrupted(batchId, err, progress);
  }
}

type StageRowsPayload =
  | {
      grid: string[][];
      calendar: "ethiopian" | "gregorian";
      /** Stage the lines that can be read and leave the rest out. */
      skip_problems?: boolean;
      source_type?: string;
      /** A saved source: its column names, and what its ids are kept against. */
      source?: string | null;
      /** Column names matched for this list. */
      mapping?: ColumnMatch[] | null;
    }
  | { rows: Record<string, unknown>[]; source_type?: string };

/**
 * Stage a list: every person is stored by the first request, which answers
 * the whole list when it is short and otherwise how far matching got.
 * A refusal of the list itself is thrown as it came; anything after the list
 * was stored is a `StagingInterrupted`.
 */
export async function stageMigration(
  payload: StageRowsPayload,
  onProgress?: (progress: StagingProgress) => void,
): Promise<MigrationBatch> {
  const answer: MigrationBatch | StagingProgress = (
    await apiClient.post("/onboarding/migration/rows", payload)
  ).data;

  if ("rows" in answer) return answer;

  return carryOnStaging(answer, onProgress);
}

/**
 * Stage a list for review: as lines of cells (`grid`), which the server reads
 * as it reads every list, or as people already keyed by field (`rows`). A long
 * list is matched a slice at a time; `onProgress` hears each.
 */
export function useStageFromRows(
  onProgress?: (progress: StagingProgress) => void,
) {
  return useMutation<MigrationBatch, unknown, StageRowsPayload>({
    mutationFn: (payload) => stageMigration(payload, onProgress),
  });
}

/** Carry on matching a list whose matching stopped. */
export function useCarryOnStaging(
  onProgress?: (progress: StagingProgress) => void,
) {
  return useMutation<MigrationBatch, unknown, string>({
    mutationFn: (batchId) =>
      carryOnStaging(
        { public_id: batchId, status: "staging", staged: 0, remaining: 1 },
        onProgress,
      ),
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
  status?: ArrivalStatus | null,
): Promise<CommitResult> {
  let left = Number.POSITIVE_INFINITY;
  let stalled = 0;
  // What the list's new people arrive as. The server keeps it from the
  // first request; it is sent with each so a retry of the first says it too.
  const body = status ? { status } : undefined;

  for (;;) {
    const result: CommitResult = (
      await apiClient.post(
        `/onboarding/migration/batches/${batchId}/commit`,
        body,
      )
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
  return useMutation<
    CommitResult,
    unknown,
    string | { batchId: string; status?: ArrivalStatus | null }
  >({
    mutationFn: (list) =>
      typeof list === "string"
        ? commitMigration(list, onProgress)
        : commitMigration(list.batchId, onProgress, list.status),
    // Settled, not only succeeded: a list that stopped part of the way has
    // still written rows, and the table has to show which.
    onSettled: (_data, _error, list) => {
      const batchId = typeof list === "string" ? list : list.batchId;
      qc.invalidateQueries({ queryKey: ["onboarding", "migration", batchId] });
      // The people it added are employees now, wherever they are listed.
      qc.invalidateQueries({ queryKey: ["employees"] });
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
