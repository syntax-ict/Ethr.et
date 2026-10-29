"use client";

import { useState } from "react";
import { Clock, Loader2, Users } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { SimpleTable } from "@/components/shared/simple-table";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  useDeviceCommands,
  useSendStaffToDevice,
  useSetDeviceTime,
  type Device,
  type DeviceCommand,
} from "@/features/devices/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { PaginationControls } from "@/components/shared/pagination-controls";

const STATUS_STYLES: Record<string, string> = {
  pending: "bg-warning-soft text-warning-on-soft border-0",
  sent: "bg-info-soft text-info-on-soft border-0",
  done: "bg-success-soft text-success-on-soft border-0",
  failed: "bg-destructive-soft text-destructive-on-soft border-0",
  cancelled: "bg-muted text-muted-foreground border-0",
};

/**
 * What ETHR has asked a device to do — set its clock, add or remove a person —
 * and what came of it. Commands only flow once an administrator turns them on
 * for the device, because removing a person from a terminal deletes the
 * fingerprints and face enrolled there.
 */
export function DeviceCommandsTab({
  device,
  canUpdate,
}: {
  device: Device;
  canUpdate: boolean;
}) {
  const { t } = useT();
  const { timeAgo } = useDateFormatters();
  const [page, setPage] = useState(1);
  const query = useDeviceCommands(device.public_id, { page, per_page: 15 });
  const setTime = useSetDeviceTime();
  const sendStaff = useSendStaffToDevice();

  const typeLabel = (type: string) =>
    ({
      set_time: t("device_commands.type_set_time"),
      upsert_user: t("device_commands.type_upsert_user"),
      query_users: t("device_commands.type_query_users"),
      delete_user: t("device_commands.type_delete_user"),
    })[type] ?? type;

  const statusLabel = (status: string) =>
    ({
      pending: t("device_commands.status_pending"),
      sent: t("device_commands.status_sent"),
      done: t("device_commands.status_done"),
      failed: t("device_commands.status_failed"),
      cancelled: t("device_commands.status_cancelled"),
    })[status] ?? status;

  return (
    <Card>
      <CardContent className="space-y-4 p-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <p className="max-w-prose text-sm text-muted-foreground">
            {!device.accepts_commands
              ? t("device_commands.not_supported")
              : device.commands_enabled
                ? t("device_commands.on_hint")
                : t("device_commands.off_hint")}
          </p>
          {canUpdate && device.commands_enabled && (
            <div className="flex shrink-0 gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={setTime.isPending}
                onClick={() =>
                  setTime.mutate(device.public_id, {
                    onSuccess: () =>
                      toast.success(t("device_commands.set_time_queued")),
                    onError: (e) =>
                      toast.error(
                        apiErrorMessage(e, t("device_commands.failed")),
                      ),
                  })
                }
              >
                {setTime.isPending ? (
                  <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                ) : (
                  <Clock className="mr-1.5 h-4 w-4" />
                )}
                {t("device_commands.set_time")}
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={sendStaff.isPending}
                onClick={() =>
                  sendStaff.mutate(device.public_id, {
                    onSuccess: (r) =>
                      toast.success(
                        t("device_commands.send_staff_queued", undefined, {
                          queued: r.queued,
                          skipped: r.skipped,
                        }),
                      ),
                    onError: (e) =>
                      toast.error(
                        apiErrorMessage(e, t("device_commands.failed")),
                      ),
                  })
                }
              >
                {sendStaff.isPending ? (
                  <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                ) : (
                  <Users className="mr-1.5 h-4 w-4" />
                )}
                {t("device_commands.send_staff")}
              </Button>
            </div>
          )}
        </div>

        <QueryBoundary
          query={query}
          loading={
            <div className="space-y-2">
              {Array.from({ length: 3 }).map((_, i) => (
                <Skeleton key={i} className="h-10" />
              ))}
            </div>
          }
          isEmpty={(data) => data.data.length === 0}
          empty={
            <p className="py-8 text-center text-sm text-muted-foreground">
              {t("device_commands.empty")}
            </p>
          }
        >
          {(data) => (
            <>
              <SimpleTable
                mobileCards
                caption={t("device_commands.title")}
                headers={[
                  t("device_commands.command"),
                  t("device_commands.person"),
                  t("common.status"),
                  t("device_detail_page.time"),
                ]}
                colClassName={["", "", "", "hidden sm:table-cell"]}
                rows={data.data.map((command: DeviceCommand) => ({
                  key: command.public_id,
                  cells: [
                    <span key="c" className="text-sm">
                      {typeLabel(command.type)}
                    </span>,
                    <span key="p" className="text-sm">
                      {command.employee?.name ?? "—"}
                      {command.device_user_id && (
                        <span className="ml-1.5 font-mono text-xs text-muted-foreground">
                          #{command.device_user_id}
                        </span>
                      )}
                    </span>,
                    <div key="s" className="space-y-1">
                      <Badge className={STATUS_STYLES[command.status] ?? ""}>
                        {statusLabel(command.status)}
                      </Badge>
                      {command.last_error && (
                        <p className="max-w-xs text-xs text-muted-foreground">
                          {command.last_error}
                        </p>
                      )}
                    </div>,
                    <span key="t" className="text-sm text-muted-foreground">
                      {command.created_at ? timeAgo(command.created_at) : "—"}
                    </span>,
                  ],
                }))}
              />
              <PaginationControls meta={data.meta} onPageChange={setPage} />
            </>
          )}
        </QueryBoundary>
      </CardContent>
    </Card>
  );
}
