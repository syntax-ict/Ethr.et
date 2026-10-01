import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components } from "@/api/generated";
import type { PaginatedResponse } from "@/api/types";

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
