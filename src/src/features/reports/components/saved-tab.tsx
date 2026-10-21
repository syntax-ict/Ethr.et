"use client";

import { useState } from "react";
import {
  Loader2,
  Bookmark,
  CalendarClock,
  Trash2,
  Mail,
  X,
  Play,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  DialogDescription,
} from "@/components/ui/dialog";
import { EmptyState } from "@/components/shared/empty-state";
import {
  useGenerateReport,
  useSavedReports,
  useDeleteSavedReport,
  useScheduleReport,
  type SavedReport,
} from "@/features/reports/api";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { reportFieldLabel } from "@/features/reports/field-labels";
import { useConfirm } from "@/components/shared/use-confirm";
import { toast } from "sonner";
import { apiErrorMessage } from "@/lib/api/error-message";
import { ErrorState } from "@/components/shared/error-state";
import { PreviewResult } from "@/features/reports/components/preview-result";

export function SavedTab() {
  const { t } = useT();
  const { requestConfirm, confirmDialog } = useConfirm();
  const { formatDate } = useDateFormatters();
  const { data, isLoading, isError, refetch } = useSavedReports();
  const deleteReport = useDeleteSavedReport();
  const runReport = useGenerateReport();
  const [scheduleFor, setScheduleFor] = useState<SavedReport | null>(null);
  const [resultFor, setResultFor] = useState<string | null>(null);

  function handleRun(r: SavedReport) {
    runReport.mutate(r.config, {
      onSuccess: (data) => {
        setResultFor(r.public_id);
        toast.success(
          t("reports_page.generated_count", "Generated :count records", {
            count: data.total,
          }),
        );
      },
      onError: (err) =>
        toast.error(apiErrorMessage(err, t("reports_page.run_failed"))),
    });
  }

  async function handleDelete(r: SavedReport) {
    if (
      !(await requestConfirm({
        title: t("reports_page.delete_saved_confirm", undefined, {
          name: r.name,
        }),
        confirmLabel: t("common.delete", "Delete"),
        variant: "destructive",
      }))
    )
      return;
    deleteReport.mutate(r.public_id, {
      onSuccess: () => toast.success(t("reports_page.template_deleted")),
    });
  }

  const reports = data?.reports ?? [];

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
      ) : reports.length === 0 ? (
        <EmptyState
          icon={Bookmark}
          title={t("reports_page.no_saved_templates")}
          description={t("reports_page.no_saved_templates_desc")}
        />
      ) : (
        <div className="grid gap-3">
          {reports.map((r) => (
            <Card key={r.public_id}>
              <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex-1">
                  <p className="font-semibold text-foreground">{r.name}</p>
                  <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <Badge variant="outline" className="capitalize">
                      {r.config.source}
                    </Badge>
                    {r.config.columns && (
                      <span>
                        · {r.config.columns.length}{" "}
                        {t("reports_page.columns_lc")}
                      </span>
                    )}
                    {r.config.filters && (
                      <span>
                        · {Object.keys(r.config.filters).length}{" "}
                        {t("reports_page.filters_lc")}
                      </span>
                    )}
                    {r.config.group_by && (
                      <span>
                        ·{" "}
                        {t(
                          "reports_page.grouped_by_field_lc",
                          "grouped by :field",
                          {
                            field: reportFieldLabel(
                              t,
                              String(r.config.group_by),
                            ),
                          },
                        )}
                      </span>
                    )}
                    <span>
                      · {t("reports_page.saved_lc")}{" "}
                      {r.created_at ? formatDate(r.created_at) : "—"}
                    </span>
                  </div>
                </div>
                <div className="flex gap-1">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => handleRun(r)}
                    disabled={runReport.isPending}
                  >
                    <Play className="mr-1 h-3 w-3" /> {t("reports_page.run")}
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setScheduleFor(r)}
                  >
                    <CalendarClock className="mr-1 h-3 w-3" />{" "}
                    {t("reports_page.schedule")}
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => handleDelete(r)}
                    aria-label={`${t("common.delete", "Delete")} ${r.name}`}
                  >
                    <Trash2
                      className="h-3 w-3 text-destructive"
                      aria-hidden="true"
                    />
                  </Button>
                </div>
              </CardContent>
              {resultFor === r.public_id && runReport.data && (
                <CardContent className="border-t pt-4">
                  <PreviewResult result={runReport.data} />
                </CardContent>
              )}
            </Card>
          ))}
        </div>
      )}

      {scheduleFor && (
        <ScheduleDialog
          report={scheduleFor}
          onClose={() => setScheduleFor(null)}
        />
      )}
      {confirmDialog}
    </div>
  );
}

export function ScheduleDialog({
  report,
  onClose,
}: {
  report: SavedReport;
  onClose: () => void;
}) {
  const { t } = useT();
  const schedule = useScheduleReport();
  const [frequency, setFrequency] = useState<"daily" | "weekly" | "monthly">(
    "weekly",
  );
  const [emailInput, setEmailInput] = useState("");
  const [recipients, setRecipients] = useState<string[]>([]);

  function addEmail() {
    const e = emailInput.trim().toLowerCase();
    if (!e || !e.includes("@")) {
      toast.error(t("reports_page.enter_valid_email"));
      return;
    }
    if (recipients.includes(e)) return;
    setRecipients((p) => [...p, e]);
    setEmailInput("");
  }

  function handleSchedule() {
    if (recipients.length === 0) {
      toast.error(t("reports_page.add_recipient"));
      return;
    }
    schedule.mutate(
      { saved_report_public_id: report.public_id, frequency, recipients },
      {
        onSuccess: () => {
          toast.success(t("reports_page.report_scheduled"));
          onClose();
        },
        onError: (err) =>
          toast.error(apiErrorMessage(err, t("reports_page.schedule_failed"))),
      },
    );
  }

  return (
    <Dialog open onOpenChange={onClose}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t("reports_page.schedule_report")}</DialogTitle>
          <DialogDescription>
            <span className="font-medium">{report.name}</span>{" "}
            {t("reports_page.will_be_sent")}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div>
            <Label>{t("reports_page.frequency")}</Label>
            <Select
              value={frequency}
              onValueChange={(v) =>
                setFrequency(v as "daily" | "weekly" | "monthly")
              }
            >
              <SelectTrigger className="mt-1">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="daily">
                  {t("reports_page.daily_6am")}
                </SelectItem>
                <SelectItem value="weekly">
                  {t("reports_page.weekly_mon_6am")}
                </SelectItem>
                <SelectItem value="monthly">
                  {t("reports_page.monthly_1st_6am")}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div>
            <Label>{t("reports_page.recipients")}</Label>
            <div className="mt-1 flex gap-2">
              <Input
                type="email"
                value={emailInput}
                onChange={(e) => setEmailInput(e.target.value)}
                onKeyDown={(e) =>
                  e.key === "Enter" && (e.preventDefault(), addEmail())
                }
                placeholder="hr@example.com"
              />
              <Button type="button" variant="outline" onClick={addEmail}>
                {t("reports_page.add")}
              </Button>
            </div>
            {recipients.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-1">
                {recipients.map((r) => (
                  <Badge
                    key={r}
                    variant="outline"
                    className="flex items-center gap-1 text-xs"
                  >
                    <Mail className="h-3 w-3" /> {r}
                    <button
                      type="button"
                      onClick={() =>
                        setRecipients((p) => p.filter((x) => x !== r))
                      }
                      className="ml-1 hover:text-destructive"
                      aria-label={`${t("common.remove", "Remove")} ${r}`}
                    >
                      <X className="h-3 w-3" aria-hidden="true" />
                    </button>
                  </Badge>
                ))}
              </div>
            )}
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button
            onClick={handleSchedule}
            disabled={schedule.isPending || recipients.length === 0}
          >
            {schedule.isPending && (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            )}
            {t("reports_page.schedule")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ── SCHEDULED TAB ──────────────────────────────────────────────
