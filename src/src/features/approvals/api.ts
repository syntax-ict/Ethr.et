import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";

export type PendingApprovals =
  operations["approval.pending"]["responses"][200]["content"]["application/json"];
export type PendingApproval = PendingApprovals["items"][number];
export type BatchApprovalPayload =
  components["schemas"]["BatchApprovalRequest"];

/**
 * `ApprovalController::batch()` answers **200 for the batch** and reports each
 * item separately — an item that was already decided, or that the caller may
 * not review, comes back as `status: "error"` with a `detail`, not as an HTTP
 * error.
 */
export type BatchApprovalResponse =
  operations["approval.batch"]["responses"][200]["content"]["application/json"];
export type BatchApprovalResult = BatchApprovalResponse["results"][number];

/** The items in a batch response that were not carried out. */
export function failedApprovals(
  response: BatchApprovalResponse,
): BatchApprovalResult[] {
  return response.results.filter((r) => r.status === "error");
}

const keys = {
  all: ["approvals"] as const,
  pending: ["approvals", "pending"] as const,
};

/**
 * Leave and attendance corrections from the caller's direct reports, plus
 * profile changes when the caller holds `employee.update`. Not paginated.
 *
 * @param enabled Gate the request on the caller's role: the dashboard panel
 * renders null for anyone below supervisor, but hooks run before that early
 * return, so without this every employee fired a request the API answers
 * with 403.
 * @param refetchInterval The dashboard keeps its rows current while the tab
 * is open; the queue page refreshes on each decision and leaves this unset.
 */
export function usePendingApprovals(enabled = true, refetchInterval?: number) {
  return useQuery<PendingApprovals>({
    queryKey: keys.pending,
    queryFn: async () => (await apiClient.get("/approvals/pending")).data,
    enabled,
    refetchInterval,
  });
}

export function useBatchApprovals() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (
      payload: BatchApprovalPayload,
    ): Promise<BatchApprovalResponse> =>
      (await apiClient.post("/approvals/batch", payload)).data,
    // Invalidated even when some items failed: the ones that went through
    // still changed what is pending.
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: keys.all });
      queryClient.invalidateQueries({ queryKey: ["leave"] });
      // The dashboard's Approvals badge counts these.
      queryClient.invalidateQueries({ queryKey: ["dashboard"] });
      // Approving a profile change writes to the employee record, so the
      // employee list and the submitter's own profile are both now stale.
      queryClient.invalidateQueries({ queryKey: ["profile"] });
      queryClient.invalidateQueries({ queryKey: ["employees"] });
    },
  });
}
