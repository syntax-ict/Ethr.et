"use client";

import Link from "next/link";
import { Download, Fingerprint, Loader2, RotateCw } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { useRetryDeviceCommands } from "@/features/devices/api";
import { useBringInPunches } from "@/features/devices/bring-in";
import { BringInProgress } from "@/features/devices/components/bring-in-progress";
import { apiErrorMessage } from "@/lib/api/error-message";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { saveBlob } from "@/lib/utils/csv-export";
import { useMigrationTerminals } from "../api";
import type { MigrationTerminalDevice } from "../types";
import { buildEnrolmentList, XLSX_MIME } from "../xlsx-grid";

/**
 * After a list is added: what the attendance terminals hold for the people it
 * brought in, one line for each terminal of their branches.
 *
 * The people reach a terminal when they are saved, by the same path as any new
 * hire, so this reads what became of that and offers the two things a person
 * can do about it: send again the people a terminal refused, and for a
 * terminal ETHR cannot write to, a list of who to enrol there and with which
 * number. Nothing is shown to someone who may not see devices, and nothing
 * when there is nothing to say.
 *
 * It also offers, to someone who may give a badge to a person, to bring in the
 * punches a terminal held for numbers this list has just given a holder. That
 * is a click, never automatic: it gives each number to that person for good,
 * and it runs as many requests as the punches need, one badge after another.
 */
export function MigrationTerminals({ batchId }: { batchId: string }) {
  const { t } = useT();
  const { hasPermission } = usePermissions();
  const query = useMigrationTerminals(batchId);
  const retry = useRetryDeviceCommands();
  const refetch = query.refetch;
  const bringIn = useBringInPunches(() => void refetch());
  const data = query.data;
  const held = data?.can_assign ? data.held : [];
  const heldMore = data?.can_assign ? data.held_more : 0;
  const phase = bringIn.state.phase;

  if (
    !data ||
    !data.visible ||
    (data.devices.length === 0 &&
      data.without_number === 0 &&
      data.without_branch === 0 &&
      held.length === 0 &&
      heldMore === 0 &&
      phase === "idle")
  ) {
    return null;
  }

  const heldPunches = held.reduce((sum, h) => sum + h.punches, 0);
  const punchesLabel = (n: number) =>
    n === 1
      ? t("migration_terminals.punches_one", "1 punch")
      : t("migration_terminals.punches", ":count punches", { count: n });

  const canUpdate = hasPermission("device.update");

  async function sendAgain(device: MigrationTerminalDevice) {
    try {
      const r = await retry.mutateAsync({
        publicId: device.device_public_id,
        batchId,
      });
      toast.success(
        t(
          "migration_terminals.retry_done",
          "Sent again: :queued. Not sent: :skipped.",
          { queued: r.queued, skipped: r.skipped },
        ),
      );
    } catch (e) {
      toast.error(
        apiErrorMessage(
          e,
          t(
            "migration_terminals.retry_failed",
            "The people could not be sent again.",
          ),
        ),
      );
    }
  }

  async function downloadList(device: MigrationTerminalDevice) {
    try {
      const workbook = await buildEnrolmentList(
        [
          t("migration_terminals.col_number", "Number"),
          t("migration_terminals.col_name", "Name"),
        ],
        device.enrol_by_hand.map((p) => [p.device_user_id, p.name]),
      );
      saveBlob(
        "ethr-terminal-enrolment.xlsx",
        new Blob([workbook], { type: XLSX_MIME }),
      );
    } catch {
      toast.error(
        t(
          "migration_terminals.download_failed",
          "The file could not be built.",
        ),
      );
    }
  }

  const employeesLink = (
    <Link
      href="/employees"
      className="text-primary underline-offset-2 hover:underline"
    >
      {t("migration_terminals.employees_link", "Open the employee list")}
    </Link>
  );

  const devicesLink = (
    <Link
      href="/devices#unmatched"
      className="text-primary underline-offset-2 hover:underline"
    >
      {t("migration_terminals.devices_link", "Open Devices")}
    </Link>
  );

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <div className="flex items-center gap-2 text-sm font-semibold text-foreground">
          <Fingerprint className="h-4 w-4" aria-hidden="true" />
          {t("migration_terminals.title", "At the attendance terminals")}
        </div>
        <p className="text-sm text-muted-foreground">
          {t(
            "migration_terminals.desc",
            "What each terminal holds for the people just brought in.",
          )}
        </p>

        {data.devices.map((device) => {
          const reached = device.queued + device.done;
          const byHand = device.enrol_by_hand.length;
          return (
            <div
              key={device.device_public_id}
              className="space-y-2 rounded-lg border p-3"
            >
              <div className="text-sm font-medium text-foreground">
                {device.device_name}
              </div>

              {(device.writable || reached > 0) && (
                <p className="text-sm text-muted-foreground">
                  {reached === 1
                    ? t(
                        "migration_terminals.sent_or_waiting_one",
                        "1 sent or waiting",
                      )
                    : t(
                        "migration_terminals.sent_or_waiting",
                        ":count sent or waiting",
                        { count: reached },
                      )}
                </p>
              )}

              {device.failed > 0 && (
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-sm text-foreground">
                    {device.failed === 1
                      ? t(
                          "migration_terminals.failed_one",
                          "1 not accepted by the terminal.",
                        )
                      : t(
                          "migration_terminals.failed",
                          ":count not accepted by the terminal.",
                          { count: device.failed },
                        )}
                    {device.last_error && (
                      <span className="block text-xs text-muted-foreground">
                        {t(
                          "migration_terminals.last_error",
                          "Its last answer: :error",
                          { error: device.last_error },
                        )}
                      </span>
                    )}
                  </p>
                  {canUpdate && device.writable && (
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={retry.isPending}
                      aria-label={t(
                        "migration_terminals.retry_aria",
                        "Try again on :device",
                        { device: device.device_name },
                      )}
                      onClick={() => void sendAgain(device)}
                    >
                      {retry.isPending ? (
                        <Loader2
                          className="mr-2 h-4 w-4 animate-spin"
                          aria-hidden="true"
                        />
                      ) : (
                        <RotateCw className="mr-2 h-4 w-4" aria-hidden="true" />
                      )}
                      {t("migration_terminals.retry", "Try again")}
                    </Button>
                  )}
                </div>
              )}

              {device.not_sent > 0 && (
                <p className="text-sm text-foreground">
                  {device.not_sent === 1
                    ? t(
                        "migration_terminals.not_sent_one",
                        "1 not sent. Its number is not one this terminal takes.",
                      )
                    : t(
                        "migration_terminals.not_sent",
                        ":count not sent. Their number is not one this terminal takes.",
                        { count: device.not_sent },
                      )}{" "}
                  {employeesLink}
                </p>
              )}

              {!device.writable && byHand > 0 && (
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-sm text-foreground">
                    {device.receives_only
                      ? byHand === 1
                        ? t(
                            "migration_terminals.receives_only_one",
                            "This terminal only sends. Enrol this person at the terminal with this number.",
                          )
                        : t(
                            "migration_terminals.receives_only",
                            "This terminal only sends. Enrol these :count people at the terminal with these numbers.",
                            { count: byHand },
                          )
                      : byHand === 1
                        ? t(
                            "migration_terminals.enrol_by_hand_one",
                            "ETHR does not send people to this terminal. Enrol this person at the terminal with this number.",
                          )
                        : t(
                            "migration_terminals.enrol_by_hand",
                            "ETHR does not send people to this terminal. Enrol these :count people at the terminal with these numbers.",
                            { count: byHand },
                          )}
                  </p>
                  <Button
                    variant="outline"
                    size="sm"
                    aria-label={t(
                      "migration_terminals.download_aria",
                      "Download the list for :device",
                      { device: device.device_name },
                    )}
                    onClick={() => void downloadList(device)}
                  >
                    <Download className="mr-2 h-4 w-4" aria-hidden="true" />
                    {t("migration_terminals.download", "Download the list")}
                  </Button>
                </div>
              )}
            </div>
          );
        })}

        {held.length > 0 && (
          <div className="space-y-2 rounded-lg border p-3">
            <p className="text-sm font-medium text-foreground">
              {held.length === 1
                ? t(
                    "migration_terminals.held_one",
                    "1 person on this list has punches waiting at a terminal (:punches).",
                    { punches: punchesLabel(heldPunches) },
                  )
                : t(
                    "migration_terminals.held",
                    ":count people on this list have punches waiting at a terminal (:punches).",
                    { count: held.length, punches: punchesLabel(heldPunches) },
                  )}
            </p>
            <ul className="divide-y text-sm">
              {held.map((h) => (
                <li
                  key={`${h.device_public_id}:${h.badge}`}
                  className="flex flex-wrap items-baseline justify-between gap-x-3 py-1"
                >
                  <span className="text-foreground">{h.employee_name}</span>
                  <span className="text-xs text-muted-foreground">
                    {t(
                      "migration_terminals.held_row",
                      ":device, number :badge, :punches",
                      {
                        device: h.device_name,
                        badge: h.badge,
                        punches: punchesLabel(h.punches),
                      },
                    )}
                  </span>
                </li>
              ))}
            </ul>
            {phase !== "running" &&
              phase !== "stopped" &&
              phase !== "failed" && (
                <>
                  <p className="text-xs text-muted-foreground">
                    {t(
                      "migration_terminals.held_warning",
                      "This gives each number to that person and cannot be undone here.",
                    )}
                  </p>
                  <Button
                    size="sm"
                    onClick={() =>
                      bringIn.start(
                        held.map((h) => ({
                          device_public_id: h.device_public_id,
                          badge: h.badge,
                          employee_public_id: h.employee_public_id,
                          punches: h.punches,
                        })),
                      )
                    }
                  >
                    {t(
                      "migration_terminals.held_button",
                      "Bring in these punches",
                    )}
                  </Button>
                </>
              )}
          </div>
        )}
        <BringInProgress
          state={bringIn.state}
          onStop={bringIn.stop}
          onCarryOn={bringIn.carryOn}
        />
        {phase === "done" && (
          <p className="text-sm text-foreground" role="status">
            {t("migration_terminals.held_done", ":count brought in.", {
              count: bringIn.state.replayed,
            })}
            {bringIn.state.refused > 0 && (
              <>
                {" "}
                {bringIn.state.refused === 1
                  ? t(
                      "migration_terminals.held_refused_one",
                      "1 punch was refused and not recorded.",
                    )
                  : t(
                      "migration_terminals.held_refused",
                      ":count punches were refused and not recorded.",
                      { count: bringIn.state.refused },
                    )}{" "}
                {devicesLink}
              </>
            )}
          </p>
        )}
        {heldMore > 0 && (
          <p className="text-sm text-muted-foreground">
            {heldMore === 1
              ? t(
                  "migration_terminals.held_more_one",
                  "1 more number has punches waiting. It is on the Devices page.",
                )
              : t(
                  "migration_terminals.held_more",
                  ":count more numbers have punches waiting. They are on the Devices page.",
                  { count: heldMore },
                )}{" "}
            {devicesLink}
          </p>
        )}

        {data.without_number > 0 && (
          <p className="text-sm text-muted-foreground">
            {data.without_number === 1
              ? t(
                  "migration_terminals.without_number_one",
                  "1 person has no badge number, so they will not reach a terminal. Give one on their profile.",
                )
              : t(
                  "migration_terminals.without_number",
                  ":count people have no badge number, so they will not reach a terminal. Give each one on their profile.",
                  { count: data.without_number },
                )}{" "}
            {employeesLink}
          </p>
        )}
        {data.without_branch > 0 && (
          <p className="text-sm text-muted-foreground">
            {data.without_branch === 1
              ? t(
                  "migration_terminals.without_branch_one",
                  "1 person has no branch, so they will not reach a terminal. Set a branch on their profile.",
                )
              : t(
                  "migration_terminals.without_branch",
                  ":count people have no branch, so they will not reach a terminal. Set a branch on each profile.",
                  { count: data.without_branch },
                )}{" "}
            {employeesLink}
          </p>
        )}
      </CardContent>
    </Card>
  );
}
