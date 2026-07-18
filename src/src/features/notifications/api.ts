import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { PaginatedResponse } from "@/api/types";

export interface Notification {
  id: string;
  type: string;
  data: Record<string, unknown>;
  read_at: string | null;
  created_at: string;
}

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
  return useQuery<{ count: number }>({
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
        queryClient.setQueryData<{ count: number }>(
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
