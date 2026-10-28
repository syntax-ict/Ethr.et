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
    // Every page, merged. Neither the device list nor the health dashboard
    // has a pager, and the API pages at 25, so a 26th device could not be
    // seen, edited or pulled, while the dashboard's Total counted it (audit
    // N69). Looped here, not via fetchAllPages, so each request keeps the
    // background flag a poll needs.
    queryFn: async ({ client, queryKey }) => {
      const query: Record<string, string | number> = {
        per_page: params?.per_page ?? 100,
      };
      if (params?.search) query.search = params.search;
      if (params?.status) query["filter[status]"] = params.status;

      const background = backgroundRequest(isPoll(client, queryKey, options));
      const rows: Device[] = [];
      let last: PaginatedResponse<Device> | null = null;
      for (let page = 1; page <= 50; page++) {
        last = (
          await apiClient.get<PaginatedResponse<Device>>("/devices", {
            params: { ...query, page },
            ...background,
          })
        ).data;
        rows.push(...last.data);
        if (page >= (last.meta?.last_page ?? 1)) break;
      }

      return { ...(last as PaginatedResponse<Device>), data: rows };
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
  roster: DeviceRoster;
}

/**
 * Where the list comes from. `live` is read from the device now. `reported`
 * (ZKTeco ADMS) is what the device last sent, as of `received_at`; while
 * `requested_at` is set, a request is waiting for its next check-in.
 */
export interface DeviceRoster {
  source: "live" | "reported";
  received_at: string | null;
  requested_at: string | null;
  can_request: boolean;
}

const enrollmentsKey = (publicId: string) => [
  ...keys.detail(publicId),
  "enrollments",
];

/**
 * Read the people enrolled on a device, each with a suggested employee match.
 * While a reported list has been asked for and not yet answered, it is read
 * again every 10 seconds; the device answers at its next check-in.
 */
export function useDeviceEnrollments(publicId: string | null) {
  return useQuery<DeviceEnrollmentsResponse>({
    queryKey: enrollmentsKey(publicId ?? ""),
    queryFn: async () =>
      (await apiClient.get(`/devices/${publicId}/enrollments`)).data,
    enabled: !!publicId,
    refetchInterval: (query) =>
      query.state.data?.roster?.requested_at ? 10_000 : false,
  });
}

/** Ask a ZKTeco ADMS device again who is enrolled on it. */
export function useRequestDeviceEnrollments() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string): Promise<{ roster: DeviceRoster }> =>
      (await apiClient.post(`/devices/${publicId}/enrollments/request`)).data,
    onSuccess: (_data, publicId) => {
      queryClient.invalidateQueries({ queryKey: enrollmentsKey(publicId) });
    },
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

// ── Commands to the device ───────────────────────────────────────────────────

export type DeviceCommand = components["schemas"]["DeviceCommandResource"];
export type SendStaffResult =
  operations["deviceCommand.sendStaff"]["responses"][202]["content"]["application/json"];

export function useDeviceCommands(
  publicId: string,
  params: { page?: number; per_page?: number },
  options: { enabled?: boolean } = {},
) {
  return useQuery<PaginatedResponse<DeviceCommand>>({
    queryKey: [...keys.detail(publicId), "commands", params],
    queryFn: async () =>
      (await apiClient.get(`/devices/${publicId}/commands`, { params })).data,
    enabled: !!publicId && (options.enabled ?? true),
  });
}

export function useSetDeviceTime() {
  return useDeviceMutation(
    async (publicId: string): Promise<DeviceCommand> =>
      (await apiClient.post(`/devices/${publicId}/commands/set-time`)).data,
  );
}

export function useSendStaffToDevice() {
  return useDeviceMutation(
    async (publicId: string): Promise<SendStaffResult> =>
      (await apiClient.post(`/devices/${publicId}/commands/send-staff`)).data,
  );
}

/** Add one employee to every device of their branch that takes commands. */
export function useEnrolEmployeeOnDevices() {
  return useDeviceMutation(
    async (employeePublicId: string): Promise<{ devices: number }> =>
      (await apiClient.post(`/employees/${employeePublicId}/enrol-on-devices`))
        .data,
  );
}

// ── Unrecognised badges (UnmatchedPunchController) ─────────────────────────

export interface UnmatchedBadge {
  device_public_id: string;
  device_name: string;
  badge: string;
  punches: number;
  first_seen: string;
  last_seen: string;
}

const UNMATCHED_KEY = ["devices", "unmatched-punches"];

/** @param enabled Gate on `device.viewAny`, which the endpoint checks. */
export function useUnmatchedBadges(enabled = true) {
  return useQuery<UnmatchedBadge[]>({
    queryKey: UNMATCHED_KEY,
    enabled,
    queryFn: async () => {
      const { data } = await apiClient.get<{ data: UnmatchedBadge[] }>(
        "/devices/unmatched-punches",
      );
      return data.data;
    },
  });
}

export function useAssignUnmatchedBadge() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: {
      device_public_id: string;
      badge: string;
      employee_public_id: string;
    }) => {
      const { data } = await apiClient.post<{
        replayed: number;
        refused: number;
      }>("/devices/unmatched-punches/assign", payload);
      return data;
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: UNMATCHED_KEY });
      void queryClient.invalidateQueries({ queryKey: ["attendance"] });
    },
  });
}

export function useDismissUnmatchedBadge() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: {
      device_public_id: string;
      badge: string;
    }) => {
      const { data } = await apiClient.post<{ dismissed: number }>(
        "/devices/unmatched-punches/dismiss",
        payload,
      );
      return data;
    },
    onSuccess: () =>
      void queryClient.invalidateQueries({ queryKey: UNMATCHED_KEY }),
  });
}
