import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type {
  AccessPolicy,
  ApplyConfigurationResult,
  CommitResult,
  ConfigurationPlan,
  GoLiveResult,
  Industry,
  LoginIdentifier,
  MigrationBatch,
  ReadinessReport,
  StagingAction,
  StagingRow,
} from "./types";

// ── Smart configuration ──────────────────────────────────────────

export function useIndustries() {
  return useQuery<Industry[]>({
    queryKey: ["onboarding", "industries"],
    queryFn: async () =>
      (await apiClient.get("/onboarding/industries")).data.data,
    staleTime: 60 * 60 * 1000,
  });
}

export function usePreviewConfiguration() {
  return useMutation<
    ConfigurationPlan,
    unknown,
    { industry: string; employee_count?: number; region?: string }
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
    { industry: string; plan: Record<string, unknown>; save?: boolean }
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

export function useStageFromRows() {
  return useMutation<
    MigrationBatch,
    unknown,
    { rows: Record<string, unknown>[]; source_type?: string }
  >({
    mutationFn: async (payload) =>
      (await apiClient.post("/onboarding/migration/rows", payload)).data,
  });
}

export function useSetRowAction() {
  return useMutation<
    StagingRow,
    unknown,
    { rowId: string; action: StagingAction; employeePublicId?: string }
  >({
    mutationFn: async ({ rowId, action, employeePublicId }) =>
      (
        await apiClient.patch(`/onboarding/migration/rows/${rowId}`, {
          action,
          employee_public_id: employeePublicId,
        })
      ).data,
  });
}

export function useCommitMigration() {
  const qc = useQueryClient();
  return useMutation<CommitResult, unknown, string>({
    mutationFn: async (batchId) =>
      (await apiClient.post(`/onboarding/migration/batches/${batchId}/commit`))
        .data,
    onSuccess: (_data, batchId) => {
      qc.invalidateQueries({ queryKey: ["onboarding", "migration", batchId] });
    },
  });
}
