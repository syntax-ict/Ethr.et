"use client";

import { CalendarClock, Trash2, Mail } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { EmptyState } from "@/components/shared/empty-state";
import {
  useScheduledReports,
  useDeleteScheduledReport,
} from "@/features/reports/api";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { useConfirm } from "@/components/shared/use-confirm";
import { toast } from "sonner";
import { ErrorState } from "@/components/shared/error-state";

export function ScheduledTab() {
  const { t } = useT();
  const { requestConfirm, confirmDialog } = useConfirm();
  // A schedule is only meaningful in the zone the tenant reads it in: "next run
  // 06:00" has to mean 06:00 to the person who set it, not 03:00 UTC.
  const { formatDateTime } = useDateFormatters();
  const { data, isLoading, isError, refetch } = useScheduledReports();
  const deleteScheduled = useDeleteScheduledReport();

  async function handleDelete(publicId: string, name: string) {
    if (
      !(await requestConfirm({
        title: t("reports_page.cancel_scheduled_confirm", undefined, { name }),
        confirmLabel: t("reports_page.cancel_schedule"),
        cancelLabel: t("reports_page.keep_schedule"),
        variant: "destructive",
      }))
    )
      return;
    deleteScheduled.mutate(publicId, {
      onSuccess: () => toast.success(t("reports_page.schedule_cancelled")),
    });
  }

  const schedules = data?.schedules ?? [];

  return (
    <div className="space-y-4">
      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-20" />
          ))}
        </div>
      ) : isError ? (
        <ErrorState onRetry={() => refetch()} />
      ) : schedules.length === 0 ? (
        <EmptyState
          icon={CalendarClock}
          title={t("reports_page.no_scheduled")}
          description={t("reports_page.no_scheduled_desc")}
        />
      ) : (
        <div className="grid gap-3">
          {schedules.map((s) => (
            <Card key={s.public_id}>
              <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    <p className="font-semibold text-foreground truncate">
                      {s.report_name}
                    </p>
                    <Badge variant="outline" className="capitalize text-[10px]">
                      {s.frequency}
                    </Badge>
                  </div>
                  <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                    <span className="flex items-center gap-1">
                      <Mail className="h-3 w-3" /> {s.recipients.length}{" "}
                      {s.recipients.length > 1
                        ? t("reports_page.recipients_lc")
                        : t("reports_page.recipient_lc")}
                    </span>
                    <span className="flex items-center gap-1">
                      <CalendarClock className="h-3 w-3" />{" "}
                      {t("reports_page.next")}:{" "}
                      {s.next_run_at ? formatDateTime(s.next_run_at) : "—"}
                    </span>
                    {s.last_run_at && (
                      <span>
                        {t("reports_page.last")}:{" "}
                        {formatDateTime(s.last_run_at)}
                      </span>
                    )}
                  </div>
                  <div className="mt-2 flex flex-wrap gap-1">
                    {s.recipients.map((r) => (
                      <Badge key={r} variant="outline" className="text-[10px]">
                        {r}
                      </Badge>
                    ))}
                  </div>
                </div>
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => handleDelete(s.public_id, s.report_name)}
                  aria-label={`${t("common.delete", "Delete")} ${s.report_name}`}
                >
                  <Trash2
                    className="h-4 w-4 text-destructive"
                    aria-hidden="true"
                  />
                </Button>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
      {confirmDialog}
    </div>
  );
}
