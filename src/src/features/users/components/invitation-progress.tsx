"use client";

import { Loader2, RotateCcw } from "lucide-react";
import { toast } from "sonner";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import { useInvitationBatch, useSendInvitations } from "@/features/users/api";
import { toastError } from "@/lib/errors";
import { useT } from "@/lib/i18n/useT";

/**
 * Where a bulk send stands. The server sends a few invitations a minute from
 * its queue, so this says plainly that it takes time, and offers to send the
 * failed ones again once nothing is left waiting.
 */
export function InvitationProgress({
  batch,
  onRetried,
}: {
  batch: string;
  /** The retry is a new batch; the caller shows that one instead. */
  onRetried?: (batch: string) => void;
}) {
  const { t } = useT();
  const query = useInvitationBatch(batch);
  const retry = useSendInvitations();
  const data = query.data;

  if (!data) {
    return null;
  }

  const done = data.sent + data.failed + data.skipped;
  const waiting = data.queued > 0;
  const label = t("invitations.progress", ":done of :total sent", {
    done: data.sent,
    total: data.total,
  });

  async function retryFailed() {
    if (!data) return;
    try {
      const result = await retry.mutateAsync({
        user_ids: data.failures.map((f) => f.user_id),
      });
      if (result.batch) onRetried?.(result.batch);
      toast.success(t("invitations.retry_queued", "Sending them again"));
    } catch (e) {
      toastError(e, t("common.error", "Something went wrong"));
    }
  }

  return (
    <Alert variant={!waiting && data.failed > 0 ? "warning" : "info"}>
      <div className="w-full space-y-2">
        <AlertTitle>
          {waiting
            ? t("invitations.sending", "Invitations are being sent")
            : t("invitations.finished", "Invitations sent")}
        </AlertTitle>
        <AlertDescription className="space-y-2">
          <Progress
            value={
              data.total === 0 ? 100 : Math.round((done / data.total) * 100)
            }
            label={label}
          />
          <p>{label}</p>
          {waiting && (
            <p className="flex items-center gap-2 text-xs">
              <Loader2 className="h-3 w-3 animate-spin" aria-hidden />
              {t(
                "invitations.sending_hint",
                "They go out a few a minute, so a large list takes a while. You can leave this page.",
              )}
            </p>
          )}
          {data.failed > 0 && (
            <div className="flex flex-wrap items-center gap-3">
              <span>
                {t("invitations.failed_count", ":count could not be sent", {
                  count: data.failed,
                })}
              </span>
              {!waiting && (
                <Button
                  size="sm"
                  variant="outline"
                  onClick={retryFailed}
                  disabled={retry.isPending}
                >
                  <RotateCcw className="mr-2 h-3 w-3" aria-hidden />
                  {t("invitations.retry", "Send them again")}
                </Button>
              )}
            </div>
          )}
        </AlertDescription>
      </div>
    </Alert>
  );
}
