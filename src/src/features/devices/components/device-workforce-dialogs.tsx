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
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { toast } from "sonner";
import {
  useDeviceEnrollments,
  useImportDeviceHistory,
  useRequestDeviceEnrollments,
  type DeviceRoster,
  type HistoryWindow,
  type MatchOutcome,
} from "../api";

function outcomeTone(outcome: MatchOutcome): string {
  return {
    matched: "var(--color-status-success, #047857)",
    probable: "var(--color-status-warning, #92400E)",
    ambiguous: "var(--color-status-error, #B91C1C)",
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
            {" · "}
            {t(
              "devices_page.discover_desc",
              "People enrolled on this device, matched against existing employees. Nothing is created here.",
            )}
          </DialogDescription>
        </DialogHeader>

        <QueryBoundary query={query}>
          {(data) => (
            <div className="space-y-3">
              {data.roster?.source === "reported" && devicePublicId && (
                <ReportedRosterBar
                  devicePublicId={devicePublicId}
                  roster={data.roster}
                  total={data.summary.total}
                />
              )}
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
 * For a device that reports its list when asked (ZKTeco ADMS): when the list
 * last arrived, whether a request is waiting, and a way to ask again. The
 * device never says its list is complete, so the count is shown as a count.
 */
function ReportedRosterBar({
  devicePublicId,
  roster,
  total,
}: {
  devicePublicId: string;
  roster: DeviceRoster;
  total: number;
}) {
  const { t } = useT();
  const { timeAgo } = useDateFormatters();
  const request = useRequestDeviceEnrollments();
  const waiting = !!roster.requested_at;

  async function ask() {
    try {
      await request.mutateAsync(devicePublicId);
      toast.success(
        t(
          "devices_page.roster_asked",
          "Asked. The device sends its list at its next check-in, usually within a minute.",
        ),
      );
    } catch {
      toast.error(
        t("devices_page.roster_ask_failed", "Could not ask the device"),
      );
    }
  }

  return (
    <div className="flex flex-col gap-2 rounded-lg border bg-muted/30 p-3 text-sm sm:flex-row sm:items-center sm:justify-between">
      <div className="space-y-0.5">
        <p className="font-medium">
          {roster.received_at
            ? t(
                "devices_page.roster_received",
                "People on the device: :count, as last reported :when",
                { count: total, when: timeAgo(roster.received_at) },
              )
            : t(
                "devices_page.roster_none",
                "The device has not sent its list yet",
              )}
        </p>
        <p className="text-muted-foreground">
          {waiting
            ? t(
                "devices_page.roster_waiting",
                "Waiting for the device. This list updates when it answers.",
              )
            : t(
                "devices_page.roster_note",
                "This device sends its list when asked. Compare the count with the terminal before relying on it.",
              )}
        </p>
      </div>
      {roster.can_request && (
        <Button
          variant="outline"
          size="sm"
          onClick={ask}
          disabled={request.isPending || waiting}
        >
          {(request.isPending || waiting) && (
            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
          )}
          {t("devices_page.roster_ask", "Ask the device again")}
        </Button>
      )}
    </div>
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
          "History import queued. Records will appear as they sync.",
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
            {" · "}
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
