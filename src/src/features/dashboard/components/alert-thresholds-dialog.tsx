"use client";

import { useState } from "react";
import { Loader2, Trash2, AlertTriangle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
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
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";
import {
  useAlertThresholds,
  useCreateAlertThreshold,
  useDeleteAlertThreshold,
  type AlertMetric,
  type AlertOperator,
  type AlertSeverity,
} from "@/features/dashboard/executive-api";

const METRICS: AlertMetric[] = [
  "turnover_rate",
  "attendance_rate_today",
  "expiring_documents_count",
  "probation_overdue_count",
  "unused_leave_count",
];

/**
 * Phase 6.7 — configure the numeric rules ComplianceCard evaluates live
 * (e.g. "alert when turnover exceeds 5%"). Configuring is tenant-wide policy,
 * so this dialog is only ever opened from a `dashboard.executive` context —
 * see ComplianceCard's gear icon, gated on `can.viewExecutiveDashboard`.
 */
export function AlertThresholdsDialog({
  open,
  onClose,
}: {
  open: boolean;
  onClose: () => void;
}) {
  const { t } = useT();
  const { data, isLoading } = useAlertThresholds();
  const create = useCreateAlertThreshold();
  const deleteThreshold = useDeleteAlertThreshold();

  const [metric, setMetric] = useState<AlertMetric>("turnover_rate");
  const [operator, setOperator] = useState<AlertOperator>("gt");
  const [value, setValue] = useState("");
  const [severity, setSeverity] = useState<AlertSeverity>("warning");

  function handleCreate() {
    const parsed = Number(value);
    if (value.trim() === "" || Number.isNaN(parsed)) {
      toast.error(t("alert_dialog.enter_value", "Enter a numeric value"));
      return;
    }
    create.mutate(
      { metric, operator, threshold_value: parsed, severity },
      {
        onSuccess: () => {
          toast.success(t("alert_dialog.created", "Alert threshold added"));
          setValue("");
        },
        onError: () =>
          toast.error(t("alert_dialog.create_failed", "Could not add rule")),
      },
    );
  }

  const thresholds = data?.thresholds ?? [];

  return (
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {t("alert_dialog.title", "Alert thresholds")}
          </DialogTitle>
          <DialogDescription>
            {t(
              "alert_dialog.description",
              "Get flagged when a KPI crosses a line you set — shown on the Compliance card and included in email digests.",
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <div className="col-span-2 sm:col-span-1">
              <Label className="text-xs">
                {t("alert_dialog.metric", "Metric")}
              </Label>
              <Select
                value={metric}
                onValueChange={(v) => setMetric(v as AlertMetric)}
              >
                <SelectTrigger className="mt-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {METRICS.map((m) => (
                    <SelectItem key={m} value={m}>
                      {t(`alert_dialog.metric_${m}`, m)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label className="text-xs">
                {t("alert_dialog.operator", "When")}
              </Label>
              <Select
                value={operator}
                onValueChange={(v) => setOperator(v as AlertOperator)}
              >
                <SelectTrigger className="mt-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="gt">
                    {t("alert_dialog.operator_gt", "exceeds")}
                  </SelectItem>
                  <SelectItem value="lt">
                    {t("alert_dialog.operator_lt", "falls below")}
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label className="text-xs">
                {t("alert_dialog.value", "Value")}
              </Label>
              <Input
                className="mt-1"
                type="number"
                value={value}
                onChange={(e) => setValue(e.target.value)}
                placeholder="5"
              />
            </div>
          </div>

          <div>
            <Label className="text-xs">
              {t("alert_dialog.severity", "Severity")}
            </Label>
            <Select
              value={severity}
              onValueChange={(v) => setSeverity(v as AlertSeverity)}
            >
              <SelectTrigger className="mt-1 w-40">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="warning">
                  {t("alert_dialog.severity_warning", "Warning")}
                </SelectItem>
                <SelectItem value="critical">
                  {t("alert_dialog.severity_critical", "Critical")}
                </SelectItem>
              </SelectContent>
            </Select>
            <Button
              className="mt-3 w-full"
              onClick={handleCreate}
              disabled={create.isPending}
            >
              {create.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("alert_dialog.add_rule", "Add rule")}
            </Button>
          </div>

          <div className="border-t pt-3">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              {t("alert_dialog.active_rules", "Active rules")}
            </p>
            {isLoading ? (
              <Skeleton className="h-16 w-full" />
            ) : thresholds.length === 0 ? (
              <EmptyState
                icon={AlertTriangle}
                title={t("alert_dialog.no_rules", "No alert rules yet")}
                description={t(
                  "alert_dialog.no_rules_desc",
                  "Add one above to start getting flagged automatically.",
                )}
              />
            ) : (
              <div className="space-y-2">
                {thresholds.map((th) => (
                  <Card key={th.public_id}>
                    <CardContent className="flex items-center justify-between gap-2 p-3">
                      <div className="min-w-0 text-sm">
                        <span className="font-medium text-foreground">
                          {t(`alert_dialog.metric_${th.metric}`, th.metric)}
                        </span>{" "}
                        <span className="text-muted-foreground">
                          {th.operator === "gt"
                            ? t("alert_dialog.operator_gt", "exceeds")
                            : t("alert_dialog.operator_lt", "falls below")}{" "}
                          {th.threshold_value}
                        </span>
                        <Badge
                          variant="outline"
                          className="ml-2 text-[10px] capitalize"
                        >
                          {th.severity}
                        </Badge>
                      </div>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="shrink-0 text-muted-foreground hover:text-destructive"
                        onClick={() => deleteThreshold.mutate(th.public_id)}
                        disabled={deleteThreshold.isPending}
                        aria-label={t("alert_dialog.remove", "Remove")}
                      >
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    </CardContent>
                  </Card>
                ))}
              </div>
            )}
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t("common.close", "Close")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
