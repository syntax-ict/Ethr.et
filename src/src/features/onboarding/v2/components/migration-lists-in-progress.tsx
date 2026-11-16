"use client";

import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { useConfirm } from "@/components/shared/use-confirm";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { useDiscardMigrationList, useMigrationListsInProgress } from "../api";
import type { MigrationListInProgress } from "../types";

/**
 * The lists this organisation started and has not finished, above the box a
 * new one is pasted into.
 *
 * A staged list is kept on the server for a month, but the page held its id
 * in memory alone: reloading, closing the tab, or a request that died left a
 * list that no screen named. Each is one line, saying what it is and where it
 * stands, never who is on it. `Open` hands it to the step, which shows it as
 * it shows a list that was just staged; this card does not resume anything.
 * `Discard` is for a list nobody will finish, and only for one that has not
 * begun to be added: once people are written the list is their record.
 */
export function MigrationListsInProgress({
  onOpen,
  openingId = null,
  openingText = null,
  disabled = false,
}: {
  onOpen: (list: MigrationListInProgress) => void;
  /** The list being opened, while it is; its button says how far matching got. */
  openingId?: string | null;
  openingText?: string | null;
  /** Something else on the screen is busy with a list. */
  disabled?: boolean;
}) {
  const { t } = useT();
  const { can } = usePermissions();
  const { timeAgo, formatDateTime } = useDateFormatters();
  const { requestConfirm, confirmDialog } = useConfirm();
  const query = useMigrationListsInProgress();
  const discard = useDiscardMigrationList();
  const lists = query.data ?? [];

  if (lists.length === 0) return null;

  const modeLabel = (mode: MigrationListInProgress["mode"]) =>
    mode === "complete"
      ? t(
          "migration_lists.mode_complete",
          "Completing people who are already here",
        )
      : t("migration_lists.mode_add", "Adding people");

  const statusLabel = (status: MigrationListInProgress["status"]) =>
    ({
      staging: t("migration_lists.status_staging", "Still being read"),
      reviewing: t(
        "migration_lists.status_reviewing",
        "Waiting for your review",
      ),
      committing: t("migration_lists.status_committing", "Being added"),
    })[status];

  const linesLabel = (n: number) =>
    n === 1
      ? t("migration_lists.lines_one", "1 line")
      : t("migration_lists.lines", ":count lines", { count: n });

  function startedLabel(list: MigrationListInProgress): string {
    const when = list.created_at ? timeAgo(list.created_at) : "";
    if (list.mine) {
      return t("migration_lists.started_you", "Started :when by you", { when });
    }
    return list.created_by_name
      ? t("migration_lists.started_by", "Started :when by :name", {
          when,
          name: list.created_by_name,
        })
      : t("migration_lists.started_other", "Started :when by someone else", {
          when,
        });
  }

  async function discardList(list: MigrationListInProgress) {
    const confirmed = await requestConfirm({
      title: t("migration_lists.discard_title", "Discard this list?"),
      description:
        list.rows === 1
          ? t(
              "migration_lists.discard_desc_one",
              "The 1 line on it is removed. Nobody on it has been added.",
            )
          : t(
              "migration_lists.discard_desc",
              "The :count lines on it are removed. Nobody on it has been added.",
              { count: list.rows },
            ),
      confirmLabel: t("migration_lists.discard", "Discard"),
      variant: "destructive",
    });
    if (!confirmed) return;

    try {
      await discard.mutateAsync(list.public_id);
      toast.success(t("migration_lists.discarded", "The list was discarded."));
    } catch (err) {
      // A list that began to be added a moment ago answers 409 and says so.
      toast.error(
        apiErrorMessage(
          err,
          t(
            "migration_lists.discard_failed",
            "The list could not be discarded.",
          ),
        ),
      );
    }
  }

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <div>
          <h3 className="text-sm font-semibold text-foreground">
            {t("migration_lists.title", "Lists in progress")}
          </h3>
          <p className="mt-1 text-xs text-muted-foreground">
            {t(
              "migration_lists.hint",
              "These were started and not finished. Open one to carry on, or discard one nobody will finish.",
            )}
          </p>
        </div>
        <ul className="divide-y rounded-md border">
          {lists.map((list) => {
            const opening = openingId === list.public_id;
            return (
              <li
                key={list.public_id}
                data-testid="list-in-progress"
                className="flex flex-wrap items-center justify-between gap-3 px-3 py-2"
              >
                <div className="min-w-0 space-y-0.5">
                  <div className="flex flex-wrap items-center gap-2 text-sm text-foreground">
                    <span className="font-medium">{modeLabel(list.mode)}</span>
                    <Badge variant="outline">{statusLabel(list.status)}</Badge>
                  </div>
                  <p
                    className="text-xs text-muted-foreground"
                    title={
                      list.created_at
                        ? formatDateTime(list.created_at)
                        : undefined
                    }
                  >
                    {startedLabel(list)} · {linesLabel(list.rows)}
                  </p>
                </div>
                <div className="flex shrink-0 gap-2">
                  <Button
                    size="sm"
                    onClick={() => onOpen(list)}
                    disabled={
                      disabled || openingId !== null || discard.isPending
                    }
                  >
                    {opening ? (
                      <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    ) : null}
                    {opening && openingText
                      ? openingText
                      : t("migration_lists.open", "Open")}
                  </Button>
                  {can.manageEmployees && list.status !== "committing" && (
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => void discardList(list)}
                      disabled={
                        disabled || openingId !== null || discard.isPending
                      }
                    >
                      {t("migration_lists.discard", "Discard")}
                    </Button>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      </CardContent>
      {confirmDialog}
    </Card>
  );
}
