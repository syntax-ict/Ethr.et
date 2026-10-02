import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";
import type { PaginatedResponse } from "@/api/types";

/**
 * From the contract, with two fields corrected: `data` is the notification's
 * `array`-cast payload — an object keyed by field — which Scramble publishes
 * as `unknown[]`; and `created_at` is stamped by the database channel's
 * Eloquent insert, so never null, though Scramble types any timestamp so.
 */
export type Notification = Omit<
  components["schemas"]["NotificationResource"],
  "data" | "created_at"
> & { data: Record<string, unknown>; created_at: string };

/**
 * The line a list shows for a notification. Every database notification sends
 * `message` except DashboardDigestNotification, which sends `title`.
 */
export function notificationText(n: Notification): string | undefined {
  const { message, title } = n.data;
  if (typeof message === "string") return message;
  if (typeof title === "string") return title;
  return undefined;
}

type UnreadCount =
  operations["notification.unreadCount"]["responses"][200]["content"]["application/json"];

export function useNotifications(params?: { page?: number }) {
  return useQuery<PaginatedResponse<Notification>>({
    queryKey: ["notifications", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/notifications", { params });
      return data;
    },
  });
}

export function useUnreadCount() {
  return useQuery<UnreadCount>({
    queryKey: ["notifications", "unread-count"],
    queryFn: async () => {
      const { data } = await apiClient.get("/notifications/unread-count");
      return data;
    },
    refetchInterval: 30000,
  });
}

/**
 * Optimistic per CLAUDE.md's Optimistic UI Policy ("Mark notification as
 * read | Yes | No downstream effects"): the read state flips instantly in
 * both the notification list and the unread count, and rolls back to
 * whatever was cached before if the server call fails.
 */
export function useMarkAsRead() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (id: string) => {
      await apiClient.put(`/notifications/${id}/read`);
    },
    onMutate: async (id: string) => {
      await queryClient.cancelQueries({ queryKey: ["notifications"] });

      const previous = queryClient.getQueriesData({
        queryKey: ["notifications"],
      });

      let wasUnread = false;

      queryClient.setQueriesData<PaginatedResponse<Notification>>(
        { queryKey: ["notifications"] },
        (old) => {
          if (!old || !Array.isArray(old.data)) return old;

          return {
            ...old,
            data: old.data.map((notification) => {
              if (notification.id !== id) return notification;
              if (!notification.read_at) wasUnread = true;
              return { ...notification, read_at: new Date().toISOString() };
            }),
          };
        },
      );

      if (wasUnread) {
        queryClient.setQueryData<UnreadCount>(
          ["notifications", "unread-count"],
          (old) => (old ? { count: Math.max(0, old.count - 1) } : old),
        );
      }

      return { previous };
    },
    onError: (_err, _id, context) => {
      context?.previous?.forEach(([queryKey, data]) => {
        queryClient.setQueryData(queryKey, data);
      });
    },
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: ["notifications"] });
    },
  });
}

// ── Preferences (type × channel opt-in matrix) ───────────────────

type PreferencesContract =
  operations["notificationPreferences.index"]["responses"][200]["content"]["application/json"];

/**
 * From the contract, with two fields corrected by hand: Scramble publishes the
 * `preferences` matrix as a string and `channel_availability.sms` as a string.
 * Both mirror NotificationPreferencesController::index() — a boolean per
 * notification type per channel, and a boolean per channel.
 */
export type NotificationPreferences = Omit<
  PreferencesContract,
  "preferences" | "channel_availability"
> & {
  preferences: Record<string, Record<string, boolean>>;
  channel_availability: Record<string, boolean>;
};

const PREFERENCES_KEY = ["notifications", "preferences"] as const;

export function useNotificationPreferences() {
  return useQuery<NotificationPreferences>({
    queryKey: PREFERENCES_KEY,
    queryFn: async () =>
      (await apiClient.get("/notifications/preferences")).data,
  });
}

/** The server answers with the whole merged matrix, so it replaces the cache. */
export function useUpdateNotificationPreferences() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (
      preferences: NotificationPreferences["preferences"],
    ): Promise<NotificationPreferences> =>
      (await apiClient.put("/notifications/preferences", { preferences })).data,
    onSuccess: (result) => {
      queryClient.setQueryData(PREFERENCES_KEY, result);
    },
  });
}

export function useMarkAllAsRead() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      await apiClient.put("/notifications/read-all");
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["notifications"] });
    },
  });
}
