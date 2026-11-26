import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type {
  AcceptDifferencesResult,
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
  ListMode,
  LoginIdentifier,
  MigrationBatch,
  MigrationListAdded,
  MigrationListInProgress,
  MigrationLogins,
  MigrationTerminals,
  PeopleRead,
  ReadinessReport,
  StagingAction,
  StagingRow,
  UndoPreview,
  UndoResult,
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

/** The key of the organisation's lists in progress; commits and discards invalidate it. */
export const MIGRATION_BATCHES_KEY = ["onboarding", "migration", "batches"];

/** A list that is gone, or is another organisation's, answers 404; asking again will not change that. */
export function isNotFound(err: unknown): boolean {
  return (err as { response?: { status?: number } })?.response?.status === 404;
}

export function useMigrationBatch(batchId: string | null) {
  return useQuery<MigrationBatch>({
    queryKey: ["onboarding", "migration", batchId],
    queryFn: async () =>
      (await apiClient.get(`/onboarding/migration/batches/${batchId}`)).data,
    enabled: !!batchId,
    retry: (failures, err) => !isNotFound(err) && failures < 1,
  });
}

/** What the lists endpoint answers: the unfinished ones, and the ones that were added. */
interface MigrationListsResponse {
  data?: MigrationListInProgress[];
  added?: MigrationListAdded[];
}

/**
 * One request serves both groups on the card. The two hooks below share its
 * key and select their own half, so the card never asks twice.
 */
function useMigrationLists<T>(
  select: (body: MigrationListsResponse) => T,
  enabled: boolean,
) {
  return useQuery<MigrationListsResponse, Error, T>({
    queryKey: MIGRATION_BATCHES_KEY,
    queryFn: async () =>
      (await apiClient.get("/onboarding/migration/batches")).data,
    select,
    enabled,
  });
}

/**
 * The organisation's lists that are not finished, newest first: staged and
 * left, or started in another tab. Asked only while no list is open.
 */
export function useMigrationListsInProgress(enabled = true) {
  return useMigrationLists((body) => body.data ?? [], enabled);
}

/**
 * The lists that were added, newest first. How many and how far back is the
 * server's to decide. Read from the request the in-progress hook makes.
 */
export function useMigrationListsAdded(enabled = true) {
  return useMigrationLists((body) => body.added ?? [], enabled);
}

/** Throw away a list nobody will finish. The server refuses one that is being added. */
export function useDiscardMigrationList() {
  const qc = useQueryClient();
  return useMutation<void, unknown, string>({
    mutationFn: async (batchId) => {
      await apiClient.delete(`/onboarding/migration/batches/${batchId}`);
    },
    onSettled: (_data, _error, batchId) => {
      qc.invalidateQueries({ queryKey: MIGRATION_BATCHES_KEY });
      qc.removeQueries({ queryKey: ["onboarding", "migration", batchId] });
    },
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
  mode: ListMode = "add",
) {
  const named = listColumns(columns);
  // Only a list that completes people says so: it has no sign-in column.
  const completing = mode === "complete" ? { mode } : {};
  return useQuery<PeopleRead>({
    queryKey: [
      "onboarding",
      "migration",
      "read",
      calendar,
      grid,
      named,
      completing,
    ],
    queryFn: async () =>
      (
        await apiClient.post("/onboarding/migration/read", {
          grid,
          calendar,
          ...named,
          ...completing,
        })
      ).data,
    enabled: enabled && grid.some((line) => line.length > 0),
    placeholderData: keepPreviousData,
    staleTime: 60_000,
    retry: false,
  });
}

/** Only what was said: a list that names no source, columns or header line sends none of them. */
export function listColumns(columns: ListColumns): ListColumns {
  return {
    ...(columns.source ? { source: columns.source } : {}),
    ...(columns.mapping ? { mapping: columns.mapping } : {}),
    ...(typeof columns.header_line === "number"
      ? { header_line: columns.header_line }
      : {}),
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
      /** The line that holds the column names; 0 for none. */
      header_line?: number | null;
      /** `complete`: nobody is added; people already here are filled in. */
      mode?: "complete";
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
  const qc = useQueryClient();
  return useMutation<MigrationBatch, unknown, string>({
    mutationFn: (batchId) =>
      carryOnStaging(
        { public_id: batchId, status: "staging", staged: 0, remaining: 1 },
        onProgress,
      ),
    // The list is read now; what the screen holds of it from before is not.
    onSuccess: (batch) => {
      qc.setQueryData(["onboarding", "migration", batch.public_id], batch);
      qc.invalidateQueries({ queryKey: MIGRATION_BATCHES_KEY });
    },
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

/**
 * "These are all them": every row held as `same_name` that names one person
 * is set to merge into them. Rows naming several people, and rows already
 * decided, are left as they are.
 */
export function useConfirmSameName() {
  return useMutation<{ confirmed: number }, unknown, string>({
    mutationFn: async (batchId) =>
      (
        await apiClient.post(
          `/onboarding/migration/batches/${batchId}/confirm-same-name`,
        )
      ).data,
  });
}

/** A run of accepting that kept answering without moving on. */
export class AcceptStalled extends Error {
  constructor() {
    super("Accepting stopped moving.");
    this.name = "AcceptStalled";
  }
}

/**
 * Accept the differences of `fields` for every merge row that may take them.
 * The server works in slices and answers `done: false` with the cursor to
 * carry on from, so the request is sent again until it answers `done`. Every
 * count is for one slice; they are added up here. Asking again is harmless:
 * a difference already accepted is not counted twice.
 */
export async function acceptDifferences(
  batchId: string,
  fields: string[],
): Promise<AcceptDifferencesResult> {
  const total: AcceptDifferencesResult = {
    done: true,
    cursor: 0,
    rows: 0,
    by_field: {},
    out_of_reach: 0,
    pay_refused: 0,
    held: 0,
  };

  for (;;) {
    const slice: AcceptDifferencesResult = (
      await apiClient.post(
        `/onboarding/migration/batches/${batchId}/accept-differences`,
        { fields, cursor: total.cursor },
      )
    ).data;

    total.rows += slice.rows;
    total.out_of_reach += slice.out_of_reach;
    total.pay_refused += slice.pay_refused;
    total.held += slice.held;
    for (const [field, count] of Object.entries(slice.by_field)) {
      total.by_field[field] = (total.by_field[field] ?? 0) + count;
    }

    if (slice.done) return total;

    // The cursor is the last row looked at: it must move forward.
    if (slice.cursor <= total.cursor) throw new AcceptStalled();
    total.cursor = slice.cursor;
  }
}

export function useAcceptDifferences() {
  const qc = useQueryClient();
  return useMutation<
    AcceptDifferencesResult,
    unknown,
    { batchId: string; fields: string[] }
  >({
    mutationFn: ({ batchId, fields }) => acceptDifferences(batchId, fields),
    // Settled, not only succeeded: a stopped run has still accepted rows.
    onSettled: (_data, _error, { batchId }) => {
      qc.invalidateQueries({ queryKey: ["onboarding", "migration", batchId] });
    },
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
      // Finished or part way, the lists in progress have changed either way.
      qc.invalidateQueries({ queryKey: MIGRATION_BATCHES_KEY });
      // The people it added are employees now, wherever they are listed.
      qc.invalidateQueries({ queryKey: ["employees"] });
    },
  });
}

/** An undo that kept answering without the list getting shorter. */
export class UndoStalled extends Error {
  constructor() {
    super("The undo stopped moving.");
    this.name = "UndoStalled";
  }
}

/** Slices in a row that may pass without the undo getting shorter. */
const UNDO_STALLED_AFTER = 2;

/**
 * Who undoing a committed list would archive and who it would keep. Asked
 * when the dialog opens and never kept: what a list's people have been used
 * for changes by the minute, so a preview is only true while it is on screen.
 */
export function useUndoPreview(batchId: string | null, enabled: boolean) {
  return useQuery<UndoPreview>({
    queryKey: ["onboarding", "migration", batchId, "undo"],
    queryFn: async () =>
      (await apiClient.get(`/onboarding/migration/batches/${batchId}/undo`))
        .data,
    enabled: enabled && !!batchId,
    gcTime: 0,
    staleTime: 0,
    refetchOnMount: "always",
  });
}

/**
 * Archive the people a committed list added. The server works in slices and
 * answers `done: false` with how many are left, so the same request is sent
 * again until it answers `done`. A slice that archived nobody and left the
 * count where it was twice over stops the loop.
 */
export async function undoMigration(
  batchId: string,
  onProgress?: (result: UndoResult) => void,
): Promise<UndoResult> {
  let left = Number.POSITIVE_INFINITY;
  let stalled = 0;

  for (;;) {
    const result: UndoResult = (
      await apiClient.post(`/onboarding/migration/batches/${batchId}/undo`, {
        confirm: true,
      })
    ).data;

    if (result.done) return result;

    onProgress?.(result);

    stalled = result.remaining < left ? 0 : stalled + 1;
    left = result.remaining;
    if (stalled >= UNDO_STALLED_AFTER) {
      throw new UndoStalled();
    }
  }
}

export function useUndoMigration(onProgress?: (result: UndoResult) => void) {
  const qc = useQueryClient();
  return useMutation<UndoResult, unknown, string>({
    mutationFn: (batchId) => undoMigration(batchId, onProgress),
    // Settled, not only succeeded: a stopped undo has still archived people.
    onSettled: (_data, _error, batchId) => {
      qc.invalidateQueries({ queryKey: ["onboarding", "migration", batchId] });
      qc.invalidateQueries({ queryKey: MIGRATION_BATCHES_KEY });
      qc.invalidateQueries({
        queryKey: ["onboarding", "migration", batchId, "logins"],
      });
      qc.invalidateQueries({
        queryKey: ["onboarding", "migration", batchId, "terminals"],
      });
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

/** What the attendance terminals hold for a committed batch's people. */
export function useMigrationTerminals(batchId: string | null) {
  return useQuery<MigrationTerminals>({
    queryKey: ["onboarding", "migration", batchId, "terminals"],
    queryFn: async () =>
      (
        await apiClient.get(
          `/onboarding/migration/batches/${batchId}/terminals`,
        )
      ).data,
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
      // The Employees list says who cannot sign in yet.
      qc.invalidateQueries({ queryKey: ["employees"] });
    },
  });
}
