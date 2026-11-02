import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import type { components } from "@/api/generated";
import { apiClient } from "@/api/client";
import { usePermissions } from "@/lib/hooks/usePermissions";
import type { OperationsStatus } from "@/features/admin/operations";

/**
 * Off-host backup storage in the platform console (BackupStorageController):
 * an S3-compatible bucket, a connection test, the archive key and the switch.
 *
 * The storage secret is write-only. The server reports `secret_set` and never
 * the value, so the form sends `secret_access_key` only when it is replaced.
 * The key is never in this state either; it comes only from `useRevealBackupKey`.
 */
export type SaveBackupStoragePayload =
  components["schemas"]["SaveBackupStorageRequest"];

export interface BackupStorageState {
  endpoint: string;
  region: string;
  bucket: string;
  access_key_id: string;
  secret_set: boolean;
  path_style: boolean;
  verified: boolean;
  verified_at: string | null;
  last_test_at: string | null;
  last_test_ok: boolean | null;
  last_test_error: string | null;
}

export interface BackupConsoleState {
  /** Where off-host copies are configured: this page, `.env`, or nowhere. */
  source: "console" | "env" | "none";
  enabled: boolean;
  /** True when the next nightly backup will be copied off the host. */
  available: boolean;
  /** Why it will not, as an operations reason code. */
  blocker: string | null;
  /** Copies kept in the bucket. */
  keep: number;
  storage: BackupStorageState | null;
  key: { revealed_at: string | null; saved_at: string | null } | null;
  health: OperationsStatus["backups"];
}

const KEY = ["admin", "backups"] as const;

export function useBackupConsole() {
  const { isSuperAdmin } = usePermissions();

  return useQuery<BackupConsoleState>({
    queryKey: KEY,
    queryFn: async () => (await apiClient.get("/admin/backups")).data,
    enabled: isSuperAdmin,
  });
}

function useBackupMutation<TVariables>(
  run: (variables: TVariables) => Promise<BackupConsoleState>,
) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: run,
    onSuccess: (data) => {
      qc.setQueryData(KEY, data);
      void qc.invalidateQueries({ queryKey: ["admin", "operations"] });
    },
  });
}

export function useSaveBackupStorage() {
  return useBackupMutation(
    async (payload: SaveBackupStoragePayload) =>
      (
        await apiClient.put<BackupConsoleState>(
          "/admin/backups/storage",
          payload,
        )
      ).data,
  );
}

export function useUpdateBackupSettings() {
  return useBackupMutation(
    async (payload: { enabled?: boolean; key_saved?: boolean }) =>
      (
        await apiClient.put<BackupConsoleState>(
          "/admin/backups/settings",
          payload,
        )
      ).data,
  );
}

export function useTestBackupStorage() {
  return useBackupMutation(
    async () =>
      (await apiClient.post<BackupConsoleState>("/admin/backups/storage/test"))
        .data,
  );
}

/** Shows the archive key once; the server records each time it is shown. */
export function useRevealBackupKey() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () =>
      (await apiClient.post<{ key: string }>("/admin/backups/key")).data.key,
    onSuccess: () => void qc.invalidateQueries({ queryKey: KEY }),
  });
}
