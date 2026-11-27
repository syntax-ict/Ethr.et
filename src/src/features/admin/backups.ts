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

/**
 * The Google Drive fallback (BackupDriveController). The client secret, the
 * refresh token and the archive key are never in this state; `secret_set` and
 * `connected` say only that they are stored.
 */
export interface BackupDriveState {
  /** The console page's own address: what Google Cloud must allow as a redirect. */
  redirect_uri: string;
  saved: boolean;
  client_id: string | null;
  secret_set: boolean;
  connected: boolean;
  account_email: string | null;
  connected_at: string | null;
  enabled: boolean;
  /** True when a copy can go to Drive now. */
  available: boolean;
  blocker: string | null;
  verified: boolean;
  verified_at: string | null;
  last_test_at: string | null;
  last_test_ok: boolean | null;
  last_test_error: string | null;
  key: { revealed_at: string | null; saved_at: string | null } | null;
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
  google_drive: BackupDriveState;
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

/** What the server's run of `ethr:backup` said, beside the refreshed page state. */
export interface BackupRunOutcome {
  ok: boolean;
  exit_code: number | null;
  output: string;
  off_host_copied: boolean;
}

/**
 * Back up now. A real backup plus an upload outlasts the client's 30 second
 * default, so this call waits longer. The page still decides success from the
 * re-read health (`last_backup_at` moving), never from this response alone: a
 * timeout on a backup the server finishes is not a failure.
 */
export const BACKUP_RUN_TIMEOUT_MS = 600000;

export function useRunBackup() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () =>
      (
        await apiClient.post<BackupConsoleState & { run: BackupRunOutcome }>(
          "/admin/backups/run",
          undefined,
          { timeout: BACKUP_RUN_TIMEOUT_MS },
        )
      ).data,
    onSettled: async () => {
      await qc.invalidateQueries({ queryKey: KEY });
      void qc.invalidateQueries({ queryKey: ["admin", "operations"] });
    },
  });
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

/** The first message of a failed call: a field's, else the server's own. */
export function backupErrorDetail(err: unknown): string | undefined {
  const data = (
    err as {
      response?: {
        data?: { detail?: string; errors?: Record<string, string[]> };
      };
    }
  ).response?.data;

  return data?.errors
    ? Object.values(data.errors)[0]?.[0]
    : (data?.detail ?? undefined);
}

const DRIVE = "/admin/backups/google-drive";

export function useSaveBackupDrive() {
  return useBackupMutation(
    async (payload: { client_id: string; client_secret: string | null }) =>
      (await apiClient.put<BackupConsoleState>(DRIVE, payload)).data,
  );
}

/** The Google consent URL; the page navigates the browser to it. */
export function useConnectBackupDrive() {
  return useMutation({
    mutationFn: async () =>
      (await apiClient.post<{ url: string }>(`${DRIVE}/connect`)).data.url,
  });
}

/** Completes the connection with what Google put in the page's address. */
export function useCompleteBackupDrive() {
  return useBackupMutation(
    async (payload: { code: string; state: string }) =>
      (await apiClient.post<BackupConsoleState>(`${DRIVE}/callback`, payload))
        .data,
  );
}

export function useDisconnectBackupDrive() {
  return useBackupMutation(
    async () => (await apiClient.delete<BackupConsoleState>(DRIVE)).data,
  );
}

export function useTestBackupDrive() {
  return useBackupMutation(
    async () =>
      (await apiClient.post<BackupConsoleState>(`${DRIVE}/test`)).data,
  );
}

export function useUpdateBackupDriveSettings() {
  return useBackupMutation(
    async (payload: { enabled?: boolean; key_saved?: boolean }) =>
      (await apiClient.put<BackupConsoleState>(`${DRIVE}/settings`, payload))
        .data,
  );
}

/** Shows Drive's own archive key; the server records each time. */
export function useRevealBackupDriveKey() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () =>
      (await apiClient.post<{ key: string }>(`${DRIVE}/key`)).data.key,
    onSuccess: () => void qc.invalidateQueries({ queryKey: KEY }),
  });
}
