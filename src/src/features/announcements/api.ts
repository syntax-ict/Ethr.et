import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { PaginatedResponse } from "@/api/types";

export interface Announcement {
  public_id: string;
  title: string;
  body: string;
  priority: "normal" | "high" | "urgent";
  target: "all" | "department" | "branch" | "role";
  target_id?: string;
  status: "draft" | "published";
  published_at: string | null;
  created_at: string;
}

export interface CreateAnnouncementPayload {
  title: string;
  body: string;
  priority?: "normal" | "high" | "urgent";
  target?: "all" | "department" | "branch" | "role";
  target_id?: string;
}

export function useAnnouncements(params?: { page?: number }) {
  return useQuery<PaginatedResponse<Announcement>>({
    queryKey: ["announcements", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/announcements", { params });
      return data;
    },
  });
}

export function useAnnouncement(publicId: string) {
  return useQuery<Announcement>({
    queryKey: ["announcements", publicId],
    queryFn: async () => {
      const { data } = await apiClient.get(`/announcements/${publicId}`);
      return data;
    },
    enabled: !!publicId,
  });
}

export function useCreateAnnouncement() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: CreateAnnouncementPayload) => {
      const { data } = await apiClient.post("/announcements", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["announcements"] });
    },
  });
}

export function useUpdateAnnouncement() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      publicId,
      ...payload
    }: CreateAnnouncementPayload & { publicId: string }) => {
      const { data } = await apiClient.put(
        `/announcements/${publicId}`,
        payload,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["announcements"] });
    },
  });
}

export function useDeleteAnnouncement() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) => {
      await apiClient.delete(`/announcements/${publicId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["announcements"] });
    },
  });
}
