"use client";

import { useState } from "react";
import { ClipboardCheck, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { CurrencyDisplay } from "@/components/shared/currency-display";
import { EmptyState } from "@/components/shared/empty-state";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  useExitClearance,
  useStartExitClearance,
  useTickExitClearanceItem,
  type ExitClearanceItem,
  type ExitClearanceShow,
} from "@/features/offboarding/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { formatETB } from "@/lib/utils/currency";

export function useOwnerLabel() {
  const { t } = useT();

  return (owner: string): string => {
    switch (owner) {
      case "finance":
        return t("exit_clearance.owner_finance", "Finance");
      case "supervisor":
        return t("exit_clearance.owner_supervisor", "Supervisor");
      default:
        return t("exit_clearance.owner_hr", "HR");
    }
  };
}

/**
 * The leaver's exit checklist (blueprint L10). It records who returned what
 * and who signed off; it never holds up the exit or the final pay.
 */
export function ExitClearanceTab({ employeeId }: { employeeId: string }) {
  const { t } = useT();
  const { can } = usePermissions();
  const query = useExitClearance(employeeId);
  const start = useStartExitClearance(employeeId);

  return (
    <div className="space-y-4">
      <div>
        <h2 className="text-lg font-semibold">
          {t("exit_clearance.title", "Exit clearance")}
        </h2>
        <p className="text-sm text-muted-foreground">
          {t(
            "exit_clearance.intro",
            "What has to be returned, handed over and settled before someone leaves. It is a record, and does not hold up the exit or the final pay.",
          )}
        </p>
      </div>

      <QueryBoundary
        query={query}
        isEmpty={(d) => d.clearance === null}
        empty={
          <EmptyState
            icon={ClipboardCheck}
            title={t("exit_clearance.none_title", "No exit clearance yet")}
            description={t(
              "exit_clearance.none_desc",
              "One starts on its own when someone resigns, is terminated or retires. Start one here for someone who left before that.",
            )}
            action={
              can.transitionEmployees ? (
                <Button
                  size="sm"
                  disabled={start.isPending}
                  onClick={() =>
                    start.mutate(undefined, {
                      onError: (err) =>
                        toast.error(
                          apiErrorMessage(
                            err,
                            t(
                              "exit_clearance.start_failed",
                              "The exit clearance could not be started.",
                            ),
                          ),
                        ),
                    })
                  }
                >
                  {start.isPending && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  {t("exit_clearance.start", "Start exit clearance")}
                </Button>
              ) : undefined
            }
          />
        }
      >
        {(data) => <ClearanceCard employeeId={employeeId} data={data} />}
      </QueryBoundary>
    </div>
  );
}

function ClearanceCard({
  employeeId,
  data,
}: {
  employeeId: string;
  data: ExitClearanceShow;
}) {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const clearance = data.clearance;
  if (clearance === null) return null;

  const owed = data.obligations.total_cents;
  const complete = clearance.status === "complete";

  return (
    <Card>
      <CardContent className="space-y-4 p-4">
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant={complete ? "outline" : "default"}>
            {complete
              ? t("exit_clearance.status_complete", "Complete")
              : t("exit_clearance.status_open", "Open")}
          </Badge>
          <span className="text-sm text-muted-foreground">
            {t("exit_clearance.progress", ":done of :total done", {
              done: clearance.done_count,
              total: clearance.item_count,
            })}
          </span>
          {clearance.leaving_date && (
            <span className="text-sm text-muted-foreground">
              {t("exit_clearance.leaving_on", "Leaving on :date", {
                date: formatDate(clearance.leaving_date),
              })}
            </span>
          )}
        </div>

        {owed > 0 && (
          <div className="rounded-lg border border-warning-edge bg-warning-soft p-3 text-sm text-warning-on-soft">
            <p className="font-medium">
              {t("exit_clearance.owed_title", "Still owed:")}{" "}
              <CurrencyDisplay cents={owed} />
            </p>
            <p className="mt-1">
              {t(
                "exit_clearance.owed_desc",
                "Loans :loans and cost sharing :cost_sharing. Finance can tick the balance line with a note saying how it is being settled.",
                {
                  loans: formatETB(data.obligations.loans_cents),
                  cost_sharing: formatETB(data.obligations.cost_sharing_cents),
                },
              )}
            </p>
          </div>
        )}

        <ul className="divide-y rounded-lg border">
          {(clearance.items ?? []).map((item) => (
            <ItemRow
              key={item.public_id}
              employeeId={employeeId}
              item={item}
              owed={owed}
            />
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}

function ItemRow({
  employeeId,
  item,
  owed,
}: {
  employeeId: string;
  item: ExitClearanceItem;
  owed: number;
}) {
  const { t } = useT();
  const { formatDateTime } = useDateFormatters();
  const ownerLabel = useOwnerLabel();
  const tick = useTickExitClearanceItem(employeeId);
  const [noteOpen, setNoteOpen] = useState(false);
  const [note, setNote] = useState("");
  const checkboxId = `exit-item-${item.public_id}`;
  const needsNote = item.kind === "obligations" && owed > 0;

  function send(done: boolean, withNote: string | null) {
    tick.mutate(
      { itemId: item.public_id, done, note: withNote },
      {
        onSuccess: () => {
          setNoteOpen(false);
          setNote("");
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t("exit_clearance.tick_failed", "The line could not be saved."),
            ),
          ),
      },
    );
  }

  function onCheckedChange(checked: boolean) {
    if (checked && needsNote) {
      setNoteOpen(true);
      return;
    }
    send(checked, null);
  }

  return (
    <li className="flex items-start gap-3 p-3">
      <Checkbox
        id={checkboxId}
        checked={item.done}
        disabled={!item.can_tick || tick.isPending}
        onCheckedChange={(v) => onCheckedChange(v === true)}
        className="mt-0.5"
      />
      <div className="min-w-0 flex-1 space-y-1">
        <div className="flex flex-wrap items-center gap-2">
          <label htmlFor={checkboxId} className="text-sm font-medium">
            {item.label}
          </label>
          <Badge variant="outline">{ownerLabel(item.owner)}</Badge>
        </div>
        {item.done && item.done_at && (
          <p className="text-xs text-muted-foreground">
            {item.done_by
              ? t("exit_clearance.done_by", "Done by :name, :when", {
                  name: item.done_by,
                  when: formatDateTime(item.done_at),
                })
              : t("exit_clearance.done_at", "Done :when", {
                  when: formatDateTime(item.done_at),
                })}
          </p>
        )}
        {item.note && <p className="text-xs">{item.note}</p>}
      </div>

      <Dialog open={noteOpen} onOpenChange={setNoteOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {t("exit_clearance.note_title", "How is the balance settled?")}
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor={`${checkboxId}-note`}>
              {t("exit_clearance.note", "Note")}
            </Label>
            <Textarea
              id={`${checkboxId}-note`}
              value={note}
              maxLength={500}
              onChange={(e) => setNote(e.target.value)}
              placeholder={t(
                "exit_clearance.note_placeholder",
                "For example, deducted from the final pay",
              )}
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setNoteOpen(false)}>
              {t("common.cancel", "Cancel")}
            </Button>
            <Button
              disabled={note.trim() === "" || tick.isPending}
              onClick={() => send(true, note.trim())}
            >
              {tick.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("exit_clearance.mark_done", "Mark done")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </li>
  );
}
