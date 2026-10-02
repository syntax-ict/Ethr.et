import {
  useMutation,
  useQuery,
  useQueryClient,
  type QueryClient,
  type QueryKey,
} from "@tanstack/react-query";
import { backgroundRequest } from "@/api/background";
import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";
import type { PaginatedResponse } from "@/api/types";
import type { DevicePayload } from "./payload";

// Shapes come from the generated contract, so a renamed resource field fails
// tsc here instead of rendering blank. The hand-written types this replaces had
// already drifted: a sync log with `records_pulled`/`synced_at`, neither of
// which DeviceSyncLogResource has ever returned.
export type Device = components["schemas"]["DeviceResource"];
export type DeviceSyncLog = components["schemas"]["DeviceSyncLogResource"];
export type DeviceStatusResult =
  operations["device.status"]["responses"][200]["content"]["application/json"];
export type SyncAllResult =
  operations["device.syncAll"]["responses"][200]["content"]["application/json"];

export type DeviceDashboard =
  operations["device.dashboard"]["responses"][200]["content"]["application/json"];

/**
 * `GET /devices/{id}/events` returns a bare `LengthAwarePaginator`, not a
 * resource collection: `last_page` sits at the top level and there is no `meta`.
 * Reading `meta.last_page` there is why the Events tab never showed a next page.
 * The contract cannot type the rows (they are built inline in
 * `DeviceController::deviceEvents`), so `DeviceEvent` mirrors that array.
 */
export type DeviceEventPage = Omit<
  components["schemas"]["LengthAwarePaginator"],
  "data"
> & { data: DeviceEvent[] };

export interface DeviceEvent {
  public_id: string;
  employee_name: string | null;
  employee_code: string | null;
  date: string | null;
  check_in: string | null;
  check_out: string | null;
  source: string;
  status: string;
  confidence_score: number | null;
  created_at: string;
}

export interface DeviceListParams {
  search?: string;
  status?: string;
  per_page?: number;
}

/**
 * Every device query sits under ["devices"], so one invalidation after a write
 * refreshes the list, the dashboard and any open detail page together. Detail
 * keys carry a "detail" segment so a device id can never collide with
 * "dashboard" or "list".
 */
const keys = {
  all: ["devices"] as const,
  list: (params?: DeviceListParams) => ["devices", "list", params] as const,
  dashboard: ["devices", "dashboard"] as const,
  detail: (id: string) => ["devices", "detail", id] as const,
};

// ── Queries ───────────────────────────────────────────────────────────────────

/**
 * A refetch of a polling query that already has data — the 30-second refresh
 * on the device dashboard — rather than the page's first load. Marked as
 * background so a dashboard left open does not hold the session past the
 * tenant's idle timeout; the first load still counts as the user being there.
 */
function isPoll(
  client: QueryClient,
  queryKey: QueryKey,
  options?: { refetchInterval?: number },
): boolean {
  return (
    !!options?.refetchInterval && client.getQueryData(queryKey) !== undefined
  );
}

export function useDevices(
  params?: DeviceListParams,
  options?: { refetchInterval?: number },
) {
  return useQuery<PaginatedResponse<Device>>({
    queryKey: keys.list(params),
    queryFn: async ({ client, queryKey }) => {
      const query: Record<string, string | number> = {};
      if (params?.search) query.search = params.search;
      if (params?.status) query["filter[status]"] = params.status;
      if (params?.per_page) query.per_page = params.per_page;
      return (
        await apiClient.get("/devices", {
          params: query,
          ...backgroundRequest(isPoll(client, queryKey, options)),
        })
      ).data;
    },
    refetchInterval: options?.refetchInterval,
  });
}

export function useDeviceDashboard(options?: { refetchInterval?: number }) {
  return useQuery<DeviceDashboard>({
    queryKey: keys.dashboard,
    queryFn: async ({ client, queryKey }) =>
      (
        await apiClient.get(
          "/devices/dashboard",
          backgroundRequest(isPoll(client, queryKey, options)),
        )
      ).data,
    refetchInterval: options?.refetchInterval,
  });
}

export function useDevice(publicId: string) {
  return useQuery<Device>({
    queryKey: keys.detail(publicId),
    queryFn: async () => (await apiClient.get(`/devices/${publicId}`)).data,
    // A static-export shell renders before `useRouteId` has the real id;
    // without this gate its first paint fires `GET /devices/`.
    enabled: !!publicId,
  });
}

export function useDeviceSyncLogs(
  publicId: string,
  params: { page: number; per_page?: number },
  options?: { enabled?: boolean },
) {
  return useQuery<PaginatedResponse<DeviceSyncLog>>({
    queryKey: [...keys.detail(publicId), "sync-logs", params],
    queryFn: async () =>
      (await apiClient.get(`/devices/${publicId}/sync-logs`, { params })).data,
    enabled: !!publicId && (options?.enabled ?? true),
  });
}

export function useDeviceEvents(
  publicId: string,
  params: { page: number; per_page?: number },
  options?: { enabled?: boolean },
) {
  return useQuery<DeviceEventPage>({
    queryKey: [...keys.detail(publicId), "events", params],
    queryFn: async () =>
      (await apiClient.get(`/devices/${publicId}/events`, { params })).data,
    enabled: !!publicId && (options?.enabled ?? true),
  });
}

// ── Writes ────────────────────────────────────────────────────────────────────
// Each invalidates the whole ["devices"] tree; toasts and navigation stay with
// the page, passed as `mutate(vars, { onSuccess, onError })`.

function useDeviceMutation<TVars, TData>(
  mutationFn: (vars: TVars) => Promise<TData>,
  alsoInvalidate: readonly (readonly string[])[] = [],
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: keys.all });
      for (const queryKey of alsoInvalidate) {
        queryClient.invalidateQueries({ queryKey });
      }
    },
  });
}

export function useCreateDevice() {
  return useDeviceMutation(
    async (payload: DevicePayload): Promise<Device> =>
      (await apiClient.post("/devices", payload)).data,
  );
}

/** Every field optional: the update rules are all `sometimes`. */
export type DeviceUpdate = Partial<DevicePayload>;

export function useUpdateDevice() {
  return useDeviceMutation(
    async (vars: {
      publicId: string;
      payload: DeviceUpdate;
    }): Promise<Device> =>
      (await apiClient.put(`/devices/${vars.publicId}`, vars.payload)).data,
  );
}

export function useDeleteDevice() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string): Promise<void> => {
      await apiClient.delete(`/devices/${publicId}`);
    },
    onSuccess: (_data, publicId) => {
      // Everything except the deleted device's own queries: refreshing those
      // would refetch a detail page that is still mounted and draw three 404s.
      const gone = keys.detail(publicId);
      queryClient.invalidateQueries({
        queryKey: keys.all,
        predicate: (q) => !gone.every((part, i) => q.queryKey[i] === part),
      });
    },
  });
}

/** Queue a pull of new punches from one device. */
export function usePullDeviceEvents() {
  return useDeviceMutation(
    async (publicId: string) =>
      (await apiClient.post(`/devices/${publicId}/pull`)).data,
    [["attendance"]],
  );
}

/** Queue a pull from every online or pending device. */
export function useSyncAllDevices() {
  return useDeviceMutation(
    async (): Promise<SyncAllResult> =>
      (await apiClient.post("/devices/sync-all")).data,
    [["attendance"]],
  );
}

/**
 * Probe a device once, on demand. A mutation rather than a polling query:
 * the probe waits on the device's connect timeout, so polling an offline
 * device would hold a PHP worker every interval. The probe also writes the
 * device's status, hence the invalidation.
 */
export function useTestDeviceConnection() {
  return useDeviceMutation(
    async (publicId: string): Promise<DeviceStatusResult> =>
      (await apiClient.get(`/devices/${publicId}/status`)).data,
  );
}

export function useRegenerateDeviceToken() {
  return useDeviceMutation(
    async (publicId: string) =>
      (await apiClient.post(`/devices/${publicId}/regenerate-token`)).data,
  );
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
    queryKey: [...keys.detail(publicId ?? ""), "enrollments"],
    queryFn: async () =>
      (await apiClient.get(`/devices/${publicId}/enrollments`)).data,
    enabled: !!publicId,
  });
}

export type HistoryWindow = "last_30" | "last_90" | "from_date" | "full";

/** One-off attendance backfill; punches are dated at their real event time. */
export function useImportDeviceHistory() {
  return useDeviceMutation(
    async (vars: {
      publicId: string;
      window: HistoryWindow;
      from_date?: string;
    }) =>
      (
        await apiClient.post(`/devices/${vars.publicId}/import-history`, {
          window: vars.window,
          from_date: vars.from_date,
        })
      ).data,
    [["attendance"]],
  );
}
