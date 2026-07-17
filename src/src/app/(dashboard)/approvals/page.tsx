"use client";

import { CheckSquare, Check, X, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { StatusBadge } from "@/components/shared/status-badge";
import { EmptyState } from "@/components/shared/empty-state";
import { RoleGate } from "@/components/shared/role-gate";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

interface PendingItem {
  type: string;
  public_id: string;
  employee_name: string;
  summary: string;
  submitted_at: string;
}

export default function ApprovalsPage() {
  const { t } = useT();
  const queryClient = useQueryClient();

  const { data, isLoading } = useQuery<{ items: PendingItem[]; total: number }>(
    {
      queryKey: ["approvals", "pending"],
      queryFn: async () => {
        const { data } = await apiClient.get("/approvals/pending");
        return data;
      },
    },
  );

  const batchAction = useMutation({
    mutationFn: async (payload: {
      actions: Array<{
        type: string;
        public_id: string;
        action: string;
        reason?: string;
      }>;
    }) => {
      const { data } = await apiClient.post("/approvals/batch", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["approvals"] });
      queryClient.invalidateQueries({ queryKey: ["leave"] });
      toast.success(t("approvals.action_completed", "Action completed"));
    },
    onError: () => toast.error(t("approvals.action_failed", "Action failed")),
  });

  function handleApprove(item: PendingItem) {
    batchAction.mutate({
      actions: [
        { type: item.type, public_id: item.public_id, action: "approve" },
      ],
    });
  }

  function handleReject(item: PendingItem) {
    batchAction.mutate({
      actions: [
        {
          type: item.type,
          public_id: item.public_id,
          action: "reject",
          reason: "Rejected by manager",
        },
      ],
    });
  }

  const items = data?.items ?? [];

  return (
    <RoleGate minRole="supervisor">
      <div className="space-y-6">
        <PageHeader
          title={t("approvals.title", "Pending Approvals")}
          description={`${items.length} ${t("approvals.items_waiting", "item(s) waiting for your review")}`}
        />

        {isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-20 w-full" />
            ))}
          </div>
        ) : items.length === 0 ? (
          <EmptyState
            icon={CheckSquare}
            title={t("approvals.empty_title", "All caught up!")}
            description={t(
              "approvals.empty_desc",
              "No pending approvals at this time",
            )}
          />
        ) : (
          <div className="space-y-3">
            {items.map((item) => (
              <Card key={item.public_id}>
                <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                  <div className="flex items-start gap-3">
                    <StatusBadge status={item.type} />
                    <div>
                      <p className="text-sm font-medium text-foreground">
                        {item.employee_name}
                      </p>
                      <p className="text-sm text-muted-foreground">
                        {item.summary}
                      </p>
                      <p className="mt-0.5 text-xs text-muted-foreground">
                        {new Date(item.submitted_at).toLocaleDateString()}
                      </p>
                    </div>
                  </div>
                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      variant="outline"
                      className="text-green-600 hover:bg-green-50 hover:text-green-700 dark:hover:bg-green-950"
                      onClick={() => handleApprove(item)}
                      disabled={batchAction.isPending}
                    >
                      {batchAction.isPending ? (
                        <Loader2 className="h-3 w-3 animate-spin" />
                      ) : (
                        <Check className="mr-1 h-3 w-3" />
                      )}
                      {t("common.approve", "Approve")}
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      className="text-red-600 hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-950"
                      onClick={() => handleReject(item)}
                      disabled={batchAction.isPending}
                    >
                      <X className="mr-1 h-3 w-3" />
                      {t("common.reject", "Reject")}
                    </Button>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}
      </div>
    </RoleGate>
  );
}
