"use client";

import Link from "next/link";
import { Download, Fingerprint, Loader2, RotateCw } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { useRetryDeviceCommands } from "@/features/devices/api";
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
 */
export function MigrationTerminals({ batchId }: { batchId: string }) {
  const { t } = useT();
  const { hasPermission } = usePermissions();
  const query = useMigrationTerminals(batchId);
  const retry = useRetryDeviceCommands();
  const data = query.data;

  if (
    !data ||
    !data.visible ||
    (data.devices.length === 0 &&
      data.without_number === 0 &&
      data.without_branch === 0)
  ) {
    return null;
  }

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
                  {t(
                    "migration_terminals.sent_or_waiting",
                    ":count sent or waiting",
                    { count: reached },
                  )}
                </p>
              )}

              {device.failed > 0 && (
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-sm text-foreground">
                    {t(
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
                  {t(
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
                      ? t(
                          "migration_terminals.receives_only",
                          "This terminal only sends. Enrol these :count people at the terminal with these numbers.",
                          { count: byHand },
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

        {data.without_number > 0 && (
          <p className="text-sm text-muted-foreground">
            {t(
              "migration_terminals.without_number",
              ":count people have no badge number, so they will not reach a terminal. Give each one on their profile.",
              { count: data.without_number },
            )}{" "}
            {employeesLink}
          </p>
        )}
        {data.without_branch > 0 && (
          <p className="text-sm text-muted-foreground">
            {t(
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
