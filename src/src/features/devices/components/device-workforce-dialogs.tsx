"use client";

import { useState } from "react";
import { Loader2, Users, History } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { SimpleTable } from "@/components/shared/simple-table";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import {
  useDeviceEnrollments,
  useImportDeviceHistory,
  type HistoryWindow,
  type MatchOutcome,
} from "../api";

function outcomeTone(outcome: MatchOutcome): string {
  return {
    matched: "var(--color-status-success, #059669)",
    probable: "var(--color-status-warning, #D97706)",
    ambiguous: "var(--color-status-error, #DC2626)",
    new: "var(--color-text-secondary, #64748B)",
  }[outcome];
}

/**
 * Read-only roster of the people enrolled on a device, each with the identity
 * resolver's suggested employee match. Confirming matches happens in the
 * migration workspace — nothing is written from here.
 */
export function DeviceEnrollmentsDialog({
  devicePublicId,
  deviceName,
  onClose,
}: {
  devicePublicId: string | null;
  deviceName?: string;
  onClose: () => void;
}) {
  const { t } = useT();
  const query = useDeviceEnrollments(devicePublicId);

  return (
    <Dialog open={!!devicePublicId} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Users className="h-4 w-4" />
            {t("devices_page.discover_users", "Discover enrolled users")}
          </DialogTitle>
          <DialogDescription>
            {deviceName}
            {" — "}
            {t(
              "devices_page.discover_desc",
              "People enrolled on this device, matched against existing employees. Nothing is created here.",
            )}
          </DialogDescription>
        </DialogHeader>

        <QueryBoundary query={query}>
          {(data) => (
            <div className="space-y-3">
              <div className="flex flex-wrap gap-2">
                {(["matched", "probable", "ambiguous", "new"] as const).map(
                  (k) => (
                    <Badge
                      key={k}
                      variant="outline"
                      style={{ color: outcomeTone(k) }}
                    >
                      {data.summary[k]} {t(`setup2.outcome_${k}`, k)}
                    </Badge>
                  ),
                )}
              </div>

              <div className="rounded-lg border">
                <SimpleTable
                  caption={t("devices_page.discover", "Discovered enrollments")}
                  maxHeight="20rem"
                  headers={[
                    t("setup2.person", "Person"),
                    t("devices_page.device_user_id", "Device ID"),
                    t("setup2.match", "Match"),
                  ]}
                  rows={data.enrollments.map((e) => ({
                    key: e.device_user_id,
                    cells: [
                      e.name ?? "—",
                      <span
                        key="id"
                        className="font-mono text-xs text-muted-foreground"
                      >
                        {e.device_user_id}
                      </span>,
                      <span
                        key="m"
                        className="text-xs font-medium"
                        style={{ color: outcomeTone(e.match.outcome) }}
                      >
                        {t(
                          `setup2.outcome_${e.match.outcome}`,
                          e.match.outcome,
                        )}
                        {e.match.confidence
                          ? ` · ${Math.round(e.match.confidence * 100)}%`
                          : ""}
                      </span>,
                    ],
                  }))}
                />
              </div>
            </div>
          )}
        </QueryBoundary>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t("common.close", "Close")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * One-off attendance backfill from a device. Punches are dated at their real
 * event time and de-duplicated, so a backfill can safely overlap live syncs.
 */
export function ImportHistoryDialog({
  devicePublicId,
  deviceName,
  onClose,
}: {
  devicePublicId: string | null;
  deviceName?: string;
  onClose: () => void;
}) {
  const { t } = useT();
  const importHistory = useImportDeviceHistory();
  const [window, setWindow] = useState<HistoryWindow>("last_90");
  const [fromDate, setFromDate] = useState("");

  async function run() {
    if (!devicePublicId) return;
    if (window === "from_date" && !fromDate) {
      toast.error(t("devices_page.pick_date", "Choose a start date"));
      return;
    }
    try {
      await importHistory.mutateAsync({
        publicId: devicePublicId,
        window,
        from_date: window === "from_date" ? fromDate : undefined,
      });
      toast.success(
        t(
          "devices_page.history_queued",
          "History import queued — records will appear as they sync.",
        ),
      );
      onClose();
    } catch {
      toast.error(t("devices_page.history_failed", "Could not start import"));
    }
  }

  return (
    <Dialog open={!!devicePublicId} onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <History className="h-4 w-4" />
            {t("devices_page.import_history", "Import attendance history")}
          </DialogTitle>
          <DialogDescription>
            {deviceName}
            {" — "}
            {t(
              "devices_page.import_history_desc",
              "Backfill past punches. Each is dated when it happened, and duplicates are skipped.",
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3">
          <div>
            <Label>{t("devices_page.window", "Period")}</Label>
            <Select
              value={window}
              onValueChange={(v) => setWindow(v as HistoryWindow)}
            >
              <SelectTrigger className="mt-1">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="last_30">
                  {t("devices_page.last_30", "Last 30 days")}
                </SelectItem>
                <SelectItem value="last_90">
                  {t("devices_page.last_90", "Last 90 days")}
                </SelectItem>
                <SelectItem value="from_date">
                  {t("devices_page.from_date", "From a specific date")}
                </SelectItem>
                <SelectItem value="full">
                  {t("devices_page.full_history", "Entire history")}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          {window === "from_date" && (
            <div>
              <Label htmlFor="from_date">
                {t("devices_page.start_date", "Start date")}
              </Label>
              <DualCalendarDateInput
                id="from_date"
                value={fromDate}
                max={new Date().toISOString().slice(0, 10)}
                onChange={setFromDate}
                className="mt-1"
              />
            </div>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t("common.cancel", "Cancel")}
          </Button>
          <Button onClick={run} disabled={importHistory.isPending}>
            {importHistory.isPending && (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            )}
            {t("devices_page.start_import", "Start import")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
