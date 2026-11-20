"use client";

import { useState } from "react";
import { Loader2, ShieldAlert } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  useDeviceQuarantine,
  useDismissQuarantine,
  useReplayQuarantine,
  type QuarantinedPunch,
} from "@/features/devices/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";

/** The reasons the server names, each with the words that say what to do. */
const REASONS: Record<string, string> = {
  "refused:employee_has_left": "employee_has_left",
  "refused:no_open_check_in": "no_open_check_in",
  "refused:method_disabled": "method_disabled",
  "refused:key_taken": "key_taken",
  "refused:device_binding": "device_binding",
  "refused:geofence_location": "geofence",
  "refused:outside_geofence": "geofence",
  clock_ahead: "clock_ahead",
  no_time: "no_time",
  error: "error",
};

/** A reason the server may add later still reads as something to look at. */
export function reasonKey(failure: string | null): string {
  return REASONS[failure ?? ""] ?? "other";
}

/**
 * Punches this terminal sent that ETHR could not record, with the reason in
 * plain words. Nothing a terminal sends is dropped: each waits here until
 * someone replays it (after fixing what stopped it) or dismisses it. Anyone
 * who may see the device sees the list; replaying and dismissing need
 * `device.update`.
 */
export function QuarantineCard({
  deviceId,
  canUpdate,
}: {
  deviceId: string;
  canUpdate: boolean;
}) {
  const { t } = useT();
  const { formatDateTime } = useDateFormatters();
  const query = useDeviceQuarantine(deviceId);
  const replay = useReplayQuarantine();
  const dismiss = useDismissQuarantine();
  const [selected, setSelected] = useState<string[]>([]);
  const [confirming, setConfirming] = useState<string[] | null>(null);

  const busy = replay.isPending || dismiss.isPending;

  function toggle(id: string, on: boolean) {
    setSelected((current) =>
      on ? [...current, id] : current.filter((value) => value !== id),
    );
  }

  function replayRows(ids: string[]) {
    replay.mutate(ids, {
      onSuccess: (result) => {
        setSelected([]);
        toast.success(
          t(
            "devices_page.quarantine.replayed",
            ":count punches were sent through again.",
            { count: result.replayed ?? 0 },
          ),
        );
        if ((result.skipped ?? 0) > 0) {
          toast.info(
            t(
              "devices_page.quarantine.skipped",
              ":count could not be sent again: the terminal gave no time.",
              { count: result.skipped ?? 0 },
            ),
          );
        }
      },
      onError: (err) =>
        toast.error(
          apiErrorMessage(
            err,
            t(
              "devices_page.quarantine.replay_failed",
              "The punches could not be sent again.",
            ),
          ),
        ),
    });
  }

  function dismissRows(ids: string[]) {
    dismiss.mutate(ids, {
      onSuccess: () => {
        setSelected([]);
        setConfirming(null);
      },
      onError: (err) =>
        toast.error(
          apiErrorMessage(
            err,
            t(
              "devices_page.quarantine.dismiss_failed",
              "The punches could not be dismissed.",
            ),
          ),
        ),
    });
  }

  return (
    <Card id="quarantine" className="scroll-mt-20">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <ShieldAlert className="h-4 w-4 text-status-warning" />
          {t("devices_page.quarantine.title", "Punches that need attention")}
        </CardTitle>
        <p className="text-sm text-muted-foreground">
          {t(
            "devices_page.quarantine.description",
            "The terminal sent these, and they could not be recorded. They are kept here. Fix what stopped them, then send them again, or dismiss them.",
          )}
        </p>
      </CardHeader>
      <CardContent>
        <QueryBoundary
          query={query}
          empty={
            <p className="py-2 text-sm text-muted-foreground">
              {t(
                "devices_page.quarantine.empty",
                "Nothing needs attention. Every punch this terminal sent was recorded.",
              )}
            </p>
          }
          isEmpty={(page) => page.data.length === 0}
        >
          {(page) => (
            <div className="space-y-3">
              {canUpdate && (
                <div className="flex flex-wrap items-center gap-2">
                  <Button
                    size="sm"
                    disabled={busy || selected.length === 0}
                    onClick={() => replayRows(selected)}
                  >
                    {replay.isPending && (
                      <Loader2 className="mr-2 h-3 w-3 animate-spin" />
                    )}
                    {t(
                      "devices_page.quarantine.replay_selected",
                      "Send selected again",
                    )}
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    disabled={busy || selected.length === 0}
                    onClick={() => setConfirming(selected)}
                  >
                    {t(
                      "devices_page.quarantine.dismiss_selected",
                      "Dismiss selected",
                    )}
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    disabled={busy}
                    onClick={() =>
                      setSelected(page.data.map((row) => row.public_id))
                    }
                  >
                    {t(
                      "devices_page.quarantine.select_all",
                      "Select all on this page",
                    )}
                  </Button>
                </div>
              )}
              <ul className="divide-y rounded-md border">
                {page.data.map((row: QuarantinedPunch) => (
                  <li
                    key={row.public_id}
                    className="flex items-start gap-3 p-3 text-sm"
                  >
                    {canUpdate && (
                      <Checkbox
                        checked={selected.includes(row.public_id)}
                        onCheckedChange={(on) =>
                          toggle(row.public_id, on === true)
                        }
                        aria-label={t(
                          "devices_page.quarantine.select_row",
                          "Select the punch of badge :badge",
                          { badge: row.badge },
                        )}
                      />
                    )}
                    <div className="min-w-0 flex-1 space-y-1">
                      <p className="font-medium">
                        {t(
                          `devices_page.quarantine.reason.${reasonKey(row.failure)}`,
                          "This punch could not be recorded.",
                        )}
                      </p>
                      <p className="text-muted-foreground">
                        {t(
                          "devices_page.quarantine.detail",
                          "Badge :badge, received :date",
                          {
                            badge: row.badge || "?",
                            date: row.received_at
                              ? formatDateTime(row.received_at)
                              : "",
                          },
                        )}
                      </p>
                      {row.occurred_raw && (
                        <p className="text-xs text-muted-foreground">
                          {t(
                            "devices_page.quarantine.on_terminal",
                            "The terminal said: :time",
                            { time: row.occurred_raw },
                          )}
                        </p>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
              {page.total > page.data.length && (
                <p className="text-xs text-muted-foreground">
                  {t(
                    "devices_page.quarantine.more",
                    ":count more are waiting. Handle these and they will show.",
                    { count: page.total - page.data.length },
                  )}
                </p>
              )}
            </div>
          )}
        </QueryBoundary>
      </CardContent>
      <ConfirmDialog
        open={confirming !== null}
        onOpenChange={(open) => {
          if (!open) setConfirming(null);
        }}
        title={t(
          "devices_page.quarantine.dismiss_title",
          "Dismiss :count punches?",
          { count: confirming?.length ?? 0 },
        )}
        description={t(
          "devices_page.quarantine.dismiss_description",
          "They will not be recorded. They are kept for a week, then removed.",
        )}
        confirmLabel={t("devices_page.quarantine.dismiss", "Dismiss")}
        variant="destructive"
        loading={dismiss.isPending}
        onConfirm={() => confirming && dismissRows(confirming)}
      />
    </Card>
  );
}
