import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components } from "@/api/generated";
import type { PaginatedResponse } from "@/api/types";
import { fetchAllPages } from "@/api/fetch-all-pages";

// Shapes come from the generated contract. The hand-written ones they replace
// had drifted: a `status` field the resource never returns (which emptied the
// dashboard card), `target` where the API reads `target_type`, and a priority
// union missing "low".
export type Announcement = components["schemas"]["AnnouncementResource"];
export type CreateAnnouncementPayload =
  components["schemas"]["StoreAnnouncementRequest"];
export type UpdateAnnouncementPayload =
  components["schemas"]["UpdateAnnouncementRequest"];
export type AnnouncementPriority = NonNullable<
  CreateAnnouncementPayload["priority"]
>;

const keys = {
  all: ["announcements"] as const,
  list: (params?: { page?: number }) =>
    ["announcements", "list", params] as const,
};

/** Published, unexpired announcements — the API applies both rules. */
/**
 * Every live announcement, all pages. The page has no pager and the API pages
 * at 25, ordered by priority before date, so once there were more than 25 a
 * recent normal one could fall behind older urgent ones and be unreachable
 * (audit N70).
 */
export function useAllAnnouncements() {
  return useQuery<Announcement[]>({
    queryKey: [...keys.list(undefined), "all"],
    queryFn: () => fetchAllPages<Announcement>("/announcements"),
  });
}

/**
 * Every announcement in any state (draft, scheduled, published, archived),
 * newest first: the publishing view, for whoever may manage announcements.
 * The API refuses it to anyone else.
 */
export function useManagedAnnouncements(enabled: boolean) {
  return useQuery<Announcement[]>({
    queryKey: [...keys.all, "manage"],
    queryFn: () => fetchAllPages<Announcement>("/announcements", { manage: 1 }),
    enabled,
  });
}

/** One page, for the dashboard widget, which shows only the top few. */
export function useAnnouncements(params?: { page?: number }) {
  return useQuery<PaginatedResponse<Announcement>>({
    queryKey: keys.list(params),
    queryFn: async () =>
      (await apiClient.get("/announcements", { params })).data,
  });
}

function useAnnouncementMutation<TVars, TData>(
  mutationFn: (vars: TVars) => Promise<TData>,
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: keys.all });
    },
  });
}

export function useCreateAnnouncement() {
  return useAnnouncementMutation(
    async (payload: CreateAnnouncementPayload): Promise<Announcement> =>
      (await apiClient.post("/announcements", payload)).data,
  );
}

export function useUpdateAnnouncement() {
  return useAnnouncementMutation(
    async (vars: {
      publicId: string;
      payload: UpdateAnnouncementPayload;
    }): Promise<Announcement> =>
      (await apiClient.put(`/announcements/${vars.publicId}`, vars.payload))
        .data,
  );
}

export function useDeleteAnnouncement() {
  return useAnnouncementMutation(async (publicId: string): Promise<void> => {
    await apiClient.delete(`/announcements/${publicId}`);
  });
}

/**
 * Give a story a cover photo, replacing any before it. The server re-encodes
 * it and serves it at an address that changes with every upload.
 */
export function useUploadAnnouncementCover() {
  return useAnnouncementMutation(
    async (vars: { publicId: string; file: File }): Promise<Announcement> => {
      const body = new FormData();
      body.append("image", vars.file);
      return (
        await apiClient.post(`/announcements/${vars.publicId}/cover`, body, {
          headers: { "Content-Type": "multipart/form-data" },
        })
      ).data;
    },
  );
}

export function useRemoveAnnouncementCover() {
  return useAnnouncementMutation(
    async (publicId: string): Promise<Announcement> =>
      (await apiClient.delete(`/announcements/${publicId}/cover`)).data,
  );
}
