import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components } from "@/api/generated";
import { fetchAllPages } from "@/api/fetch-all-pages";
import type { TenantUser } from "@/features/users/api";

type Schemas = components["schemas"];

/** One approval chain: an ordered list of steps for one request type. */
export type ApprovalWorkflow = Schemas["ApprovalWorkflowResource"];
export type ApprovalWorkflowStep = Schemas["ApprovalWorkflowStepResource"];
export type ApprovalWorkflowPayload = Schemas["StoreApprovalWorkflowRequest"];
export type ApprovalRequestType = Schemas["ApprovalRequestType"];
export type ApproverKind = Schemas["ApproverKind"];

const key = ["settings", "approval-workflows"] as const;

/** `GET /settings/approval-workflows`: every chain, by type then priority. */
export function useApprovalWorkflows() {
  return useQuery<ApprovalWorkflow[]>({
    queryKey: key,
    queryFn: async () =>
      (await apiClient.get<ApprovalWorkflow[]>("/settings/approval-workflows"))
        .data,
  });
}

export function useSaveApprovalWorkflow() {
  const queryClient = useQueryClient();

  return useMutation<
    ApprovalWorkflow,
    unknown,
    { publicId: string | null; payload: ApprovalWorkflowPayload }
  >({
    mutationFn: async ({ publicId, payload }) =>
      publicId === null
        ? (await apiClient.post("/settings/approval-workflows", payload)).data
        : (
            await apiClient.put(
              `/settings/approval-workflows/${publicId}`,
              payload,
            )
          ).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: key }),
  });
}

export function useDeleteApprovalWorkflow() {
  const queryClient = useQueryClient();

  return useMutation<void, unknown, string>({
    mutationFn: async (publicId) => {
      await apiClient.delete(`/settings/approval-workflows/${publicId}`);
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: key }),
  });
}

/**
 * Every active user, for a step that names one person. All pages: a picker
 * that showed the first 25 made the 26th person unpickable.
 */
export function useApproverUsers(enabled: boolean) {
  return useQuery<TenantUser[]>({
    queryKey: ["users", "all", "active"],
    queryFn: async () =>
      (await fetchAllPages<TenantUser>("/users")).filter(
        (u) => u.status === "active",
      ),
    enabled,
    staleTime: 5 * 60 * 1000,
  });
}
