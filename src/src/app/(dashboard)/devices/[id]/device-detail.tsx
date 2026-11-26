"use client";

import { useState } from "react";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useEntityTitle } from "@/components/layouts/page-actions-slot";
import {
  RefreshCw,
  Signal,
  Loader2,
  Pencil,
  Trash2,
  RotateCw,
  CheckCircle,
  XCircle,
  AlertTriangle,
  Clock,
  Wifi,
  WifiOff,
  Activity,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { QuarantineCard } from "@/features/devices/components/quarantine-card";
import { Label } from "@/components/ui/label";
import { SimpleTable } from "@/components/shared/simple-table";

import { RoleGate } from "@/components/shared/role-gate";
import { DeviceCommandsTab } from "@/features/devices/components/device-commands-tab";
import { AdmsSetupCard } from "@/features/devices/components/adms-setup-card";
import {
  DeviceClockNote,
  clockValue,
} from "@/features/devices/components/device-clock-note";
import { DeviceTodayCard } from "@/features/devices/components/device-today";
import { clockFinding } from "@/features/devices/clock";
import {
  DeviceHeardLine,
  DeviceKeepAliveLine,
  WebhookRejectedLine,
  SyncErrorHint,
  syncErrorHintKeys,
} from "@/features/devices/components/device-heard";
import { DeviceFormDialog } from "@/features/devices/components/device-form";
import { branchesApi } from "@/features/organization/api";
import { DeleteDeviceDialog } from "@/features/devices/components/delete-device-dialog";
import { PushSetup } from "@/features/devices/components/push-setup";
import { RegenerateTokenDialog } from "@/features/devices/components/regenerate-token-dialog";
import {
  useDeleteDevice,
  useDevice,
  useDeviceEvents,
  useDeviceSyncLogs,
  usePullDeviceEvents,
  useTestDeviceConnection,
  useUpdateDevice,
  type Device,
  type DeviceEvent,
  type DeviceSyncLog,
  type DeviceUpdate,
  isPulled,
} from "@/features/devices/api";
import {
  deviceStatusBadgeClass,
  deviceStatusDotClass,
  deviceStatusLabelKey,
} from "@/features/devices/status";
import { useRouteId } from "@/lib/hooks/useRouteId";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { apiErrorMessage } from "@/lib/api/error-message";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { ErrorState } from "@/components/shared/error-state";
import { PaginationControls } from "@/components/shared/pagination-controls";

/** A 404 means the record is gone; anything else means the request failed. */
function isNotFound(error: unknown): boolean {
  return (
    (error as { response?: { status?: number } } | null)?.response?.status ===
    404
  );
}

const ADAPTER_LABELS: Record<string, string> = {
  hikvision: "Hikvision",
  zkteco: "ZKTeco",
  zkteco_adms: "ZKTeco (ADMS)",
  suprema: "Suprema",
  mock: "Mock (Simulator)",
};

const SYNC_STATUS_ICON: Record<string, React.ReactNode> = {
  success: <CheckCircle className="h-4 w-4 text-status-success" />,
  partial: <AlertTriangle className="h-4 w-4 text-status-warning" />,
  failed: <XCircle className="h-4 w-4 text-status-error" />,
  offline: <WifiOff className="h-4 w-4 text-muted-foreground" />,
  running: <Loader2 className="h-4 w-4 animate-spin text-status-info" />,
};

export function DeviceDetail({ routeId }: { routeId: string }) {
  const { t } = useT();
  // Punch times render in the tenant's timezone, not the browser's — §12g.
  const { formatDate, formatTime, timeAgo } = useDateFormatters();
  const id = useRouteId(routeId) ?? "";
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [syncLogPage, setSyncLogPage] = useState(1);
  const [eventPage, setEventPage] = useState(1);

  const { data: device, isLoading, isError, error, refetch } = useDevice(id);
  // Ends the title bar's breadcrumb with this record (it had no back
  // trail but a separate "Back" button).
  useEntityTitle(device?.name);

  // Child queries wait for the device itself, so a deleted or foreign id
  // costs one 404 rather than three.
  const {
    data: syncLogs,
    isLoading: syncLogsLoading,
    isError: syncLogsError,
    refetch: refetchSyncLogs,
  } = useDeviceSyncLogs(
    id,
    { page: syncLogPage, per_page: 10 },
    { enabled: !!device },
  );

  const {
    data: events,
    isLoading: eventsLoading,
    isError: eventsError,
    refetch: refetchEvents,
  } = useDeviceEvents(
    id,
    { page: eventPage, per_page: 15 },
    { enabled: !!device },
  );

  const pullMutation = usePullDeviceEvents();
  const testMutation = useTestDeviceConnection();
  const deleteMutation = useDeleteDevice();
  const updateMutation = useUpdateDevice();

  function pull() {
    pullMutation.mutate(id, {
      onSuccess: () => toast.success(t("devices_page.pull_initiated")),
      onError: (err) =>
        toast.error(apiErrorMessage(err, t("devices_page.pull_failed"))),
    });
  }

  function testConnection() {
    testMutation.mutate(id, {
      onSuccess: ({ status, error_code }) => {
        if (status === "online") {
          toast.success(t("devices_page.online_responding"));
        } else {
          const hint = syncErrorHintKeys(error_code, device?.adapter_type)
            .map((k) => t(k))
            .join(" ");
          toast.error(
            `${t("devices_page.device_status", "The device is :status", {
              status: t(`devices_page.${status}`, status),
            })}${hint ? ` ${hint}` : ""}`,
          );
        }
      },
      onError: (err) =>
        toast.error(apiErrorMessage(err, t("devices_page.test_failed"))),
    });
  }

  // Pull and edit need device.update, delete needs device.delete. The seeded
  // HR role, which this page admits, holds neither (N54).
  const { hasPermission } = usePermissions();
  const canUpdate = hasPermission("device.update");
  const canDelete = hasPermission("device.delete");

  // Regenerating changes the address the terminal posts to, so it asks why first.
  const [confirmRegen, setConfirmRegen] = useState(false);

  function remove() {
    deleteMutation.mutate(id, {
      onSuccess: () => {
        toast.success(t("devices_page.deleted"));
        window.location.href = "/devices";
      },
      onError: (err) =>
        toast.error(apiErrorMessage(err, t("devices_page.delete_failed"))),
    });
  }

  function update(payload: DeviceUpdate) {
    updateMutation.mutate(
      { publicId: id, payload },
      {
        onSuccess: () => {
          toast.success(t("devices_page.updated"));
          setEditOpen(false);
        },
        onError: (err: unknown) =>
          toast.error(
            apiErrorMessage(err, t("device_detail_page.update_failed")),
          ),
      },
    );
  }

  if (!id || isLoading) {
    return (
      <RoleGate anyPermission={["viewDevices"]}>
        <div className="space-y-6">
          <Skeleton className="h-8 w-48" />
          <div className="grid gap-4 sm:grid-cols-3">
            <Skeleton className="h-32" />
            <Skeleton className="h-32" />
            <Skeleton className="h-32" />
          </div>
          <Skeleton className="h-64" />
        </div>
      </RoleGate>
    );
  }

  // "Not found" means a 404; any other failure is a failed request.
  if (isError && !isNotFound(error)) {
    return <ErrorState onRetry={() => refetch()} />;
  }

  if (!device) {
    return (
      <div className="py-16 text-center text-muted-foreground">
        {t("device_detail_page.not_found")}
      </div>
    );
  }

  const isOnline = device.status === "online";
  // Never called, so nothing to test or pull: see DeviceHeardLine.
  const sendsOnly = device.receives_only === true;
  const pulled = isPulled(device);
  const finding = clockFinding(
    device.clock_offset_seconds ?? null,
    device.clock_measurable ?? null,
  );
  const showClock = finding.kind !== "unknown";
  const syncLogItems: DeviceSyncLog[] = syncLogs?.data ?? [];
  const eventItems: DeviceEvent[] = events?.data ?? [];
  const syncLogPages = syncLogs?.meta.last_page ?? 1;
  const eventPages = events?.last_page ?? 1;

  return (
    <RoleGate anyPermission={["viewDevices"]}>
      <div className="space-y-6">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-semibold tracking-tight text-foreground">
                {device.name}
              </h1>
              <Badge
                variant="outline"
                className={deviceStatusBadgeClass(device.status)}
              >
                <span
                  className={`mr-1.5 inline-block h-1.5 w-1.5 rounded-full ${deviceStatusDotClass(device.status)}`}
                />
                {statusLabel(device.status, t)}
              </Badge>
            </div>
            <p className="mt-1 text-sm text-muted-foreground">
              {device.adapter_type === "mock"
                ? t("devices_page.mock_simulator")
                : (ADAPTER_LABELS[device.adapter_type] ?? device.adapter_type)}
              {device.location_description &&
                ` · ${device.location_description}`}
              {device.branch?.name && ` · ${device.branch.name}`}
            </p>
            {!pulled && (
              <div className="mt-2 text-sm text-muted-foreground">
                <DeviceHeardLine device={device} />
                <DeviceKeepAliveLine
                  lastKeepaliveAt={device.last_keepalive_at}
                />
              </div>
            )}
            <WebhookRejectedLine
              rejected={device.webhook_rejected}
              devicePublicId={device.public_id}
            />
          </div>
          <div className="flex items-center gap-2">
            {!sendsOnly && (
              <Button
                variant="outline"
                size="sm"
                onClick={testConnection}
                disabled={testMutation.isPending}
              >
                {testMutation.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <Signal className="mr-2 h-4 w-4" />
                )}
                {t("devices_page.test")}
              </Button>
            )}
            {/* A terminal that pushes its punches (ADMS, or a Hikvision on the office network) has nothing to pull. */}
            {canUpdate && pulled && (
              <Button
                variant="outline"
                size="sm"
                onClick={pull}
                disabled={pullMutation.isPending}
              >
                {pullMutation.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <RefreshCw className="mr-2 h-4 w-4" />
                )}
                {t("devices_page.pull")}
              </Button>
            )}
            {canUpdate && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => setEditOpen(true)}
              >
                <Pencil className="mr-2 h-4 w-4" /> {t("common.edit")}
              </Button>
            )}
            {canDelete && (
              <Button
                variant="destructive"
                size="sm"
                onClick={() => setDeleteOpen(true)}
              >
                <Trash2 className="mr-2 h-4 w-4" /> {t("common.delete")}
              </Button>
            )}
          </div>
        </div>

        {/* Info Cards */}
        <div
          className={
            showClock
              ? "grid gap-4 sm:grid-cols-2 xl:grid-cols-5"
              : "grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
          }
        >
          <InfoCard
            icon={Activity}
            label={t("device_detail_page.total_records")}
            value={device.attendance_records_count?.toLocaleString() ?? "0"}
          />
          <InfoCard
            icon={RefreshCw}
            label={t("device_detail_page.total_syncs")}
            value={device.sync_logs_count?.toLocaleString() ?? "0"}
          />
          <InfoCard
            icon={Clock}
            label={
              sendsOnly
                ? t("devices_page.last_heard_label")
                : t("devices_dashboard_page.last_sync")
            }
            value={
              device.last_sync_at
                ? timeAgo(device.last_sync_at)
                : t("devices_page.never")
            }
          />
          {pulled && (
            <InfoCard
              icon={isOnline ? Wifi : WifiOff}
              label={t("device_detail_page.auto_sync")}
              value={
                device.auto_sync
                  ? t(
                      "devices_page.every_minutes",
                      "At most every :count min",
                      {
                        count: device.sync_interval_minutes,
                      },
                    )
                  : t("device_detail_page.disabled")
              }
            />
          )}
          {showClock && (
            <InfoCard
              icon={Clock}
              label={t("device_clock.label")}
              value={clockValue(finding, t)}
            />
          )}
        </div>

        <DeviceClockNote device={device} />

        <DeviceTodayCard today={device.today} />

        <AdmsSetupCard device={device} />

        {/* Webhook URL */}
        {device.has_webhook_token && device.uses_webhook_token && (
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-sm">
                {t("device_detail_page.webhook_config")}
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-3">
                {device.receives_only && device.webhook_url_masked && (
                  <PushSetup url={device.webhook_url_masked} masked />
                )}
                <div>
                  <Label className="text-xs text-muted-foreground">
                    {t("device_token.masked_label")}
                  </Label>
                  <code
                    data-testid="masked-address"
                    className="mt-1 block rounded border bg-muted/50 px-3 py-2 text-xs font-mono break-all"
                  >
                    {device.webhook_url_masked ?? t("common.none", "None")}
                  </code>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {t("device_token.masked_hint")}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setConfirmRegen(true)}
                  >
                    <RotateCw className="mr-2 h-3 w-3" />
                    {t("device_token.regenerate_to_see")}
                  </Button>
                </div>
              </div>
            </CardContent>
          </Card>
        )}

        <QuarantineCard deviceId={device.public_id} canUpdate={canUpdate} />

        {/* Tabs: Sync History + Events */}
        <Tabs defaultValue="syncs">
          <TabsList>
            <TabsTrigger value="syncs">
              {t("device_detail_page.sync_history")}
            </TabsTrigger>
            <TabsTrigger value="events">
              {t("device_detail_page.attendance_events")}
            </TabsTrigger>
            <TabsTrigger value="commands">
              {t("device_commands.title")}
            </TabsTrigger>
          </TabsList>

          <TabsContent value="syncs" className="mt-4">
            <Card>
              <CardContent className="p-0">
                {syncLogsLoading ? (
                  <div className="space-y-2 p-4">
                    {Array.from({ length: 3 }).map((_, i) => (
                      <Skeleton key={i} className="h-12" />
                    ))}
                  </div>
                ) : syncLogsError ? (
                  <ErrorState onRetry={() => refetchSyncLogs()} />
                ) : syncLogItems.length === 0 ? (
                  <div className="py-12 text-center text-sm text-muted-foreground">
                    {sendsOnly || device.adapter_type === "zkteco_adms"
                      ? t("device_detail_page.push_no_sync_history")
                      : t("device_detail_page.no_sync_history")}
                  </div>
                ) : (
                  <>
                    <SimpleTable
                      mobileCards
                      caption={t(
                        "device_detail_page.sync_history",
                        "Sync history",
                      )}
                      headers={[
                        t("common.status"),
                        t("device_detail_page.trigger"),
                        t("device_detail_page.found"),
                        t("device_detail_page.processed"),
                        t("devices_dashboard_page.failed"),
                        t("device_detail_page.duration"),
                        t("device_detail_page.time"),
                      ]}
                      align={[
                        "left",
                        "left",
                        "right",
                        "right",
                        "right",
                        "right",
                        "left",
                      ]}
                      colClassName={[
                        "",
                        "",
                        "hidden sm:table-cell",
                        "",
                        "hidden md:table-cell",
                        "hidden md:table-cell",
                        "",
                      ]}
                      rows={syncLogItems.map((log) => ({
                        key: log.public_id,
                        cells: [
                          <div key="s" className="text-sm">
                            <div className="flex items-center gap-2">
                              {SYNC_STATUS_ICON[log.status] ?? null}
                              <span className="capitalize">
                                {syncStatusLabel(log.status, t)}
                              </span>
                            </div>
                            {log.error_message && log.status !== "success" && (
                              <div className="mt-1 space-y-1 text-xs break-words text-muted-foreground">
                                <p className="font-mono">{log.error_message}</p>
                                {syncErrorHintKeys(
                                  log.error_code,
                                  device.adapter_type,
                                ).length > 0 && (
                                  <p>
                                    <SyncErrorHint
                                      code={log.error_code}
                                      adapterType={device.adapter_type}
                                    />
                                  </p>
                                )}
                              </div>
                            )}
                          </div>,
                          <span
                            key="t"
                            className="capitalize text-muted-foreground"
                          >
                            {log.triggered_by}
                          </span>,
                          <span
                            key="f"
                            className="tabular-nums text-muted-foreground"
                          >
                            {log.events_found}
                          </span>,
                          <span key="p" className="tabular-nums font-medium">
                            {log.events_processed}
                          </span>,
                          <span
                            key="ef"
                            className="tabular-nums text-muted-foreground"
                          >
                            {log.events_failed > 0 ? (
                              <span className="text-status-error">
                                {log.events_failed}
                              </span>
                            ) : (
                              "0"
                            )}
                          </span>,
                          <span
                            key="d"
                            className="text-xs text-muted-foreground"
                          >
                            {log.duration_ms ? `${log.duration_ms}ms` : "—"}
                          </span>,
                          <span
                            key="ts"
                            className="text-xs text-muted-foreground"
                          >
                            {log.started_at ? timeAgo(log.started_at) : "—"}
                          </span>,
                        ],
                      }))}
                    />
                    <PaginationControls
                      meta={{
                        current_page: syncLogPage,
                        last_page: syncLogPages,
                      }}
                      onPageChange={setSyncLogPage}
                    />
                  </>
                )}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="events" className="mt-4">
            <Card>
              <CardContent className="p-0">
                {eventsLoading ? (
                  <div className="space-y-2 p-4">
                    {Array.from({ length: 3 }).map((_, i) => (
                      <Skeleton key={i} className="h-12" />
                    ))}
                  </div>
                ) : eventsError ? (
                  <ErrorState onRetry={() => refetchEvents()} />
                ) : eventItems.length === 0 ? (
                  <div className="py-12 text-center text-sm text-muted-foreground">
                    {t("device_detail_page.no_events")}
                  </div>
                ) : (
                  <>
                    <SimpleTable
                      mobileCards
                      caption={t("device_detail_page.events", "Events")}
                      headers={[
                        t("attendance.employee"),
                        t("common.date"),
                        t("common.check_in"),
                        t("common.check_out"),
                        t("common.status"),
                        t("device_detail_page.confidence"),
                      ]}
                      align={["left", "left", "left", "left", "left", "right"]}
                      colClassName={[
                        "",
                        "",
                        "hidden sm:table-cell",
                        "hidden sm:table-cell",
                        "",
                        "hidden md:table-cell",
                      ]}
                      rows={eventItems.map((ev) => ({
                        key: ev.public_id,
                        cells: [
                          <div key="e">
                            <p className="font-medium">{ev.employee_name}</p>
                            {ev.employee_code && (
                              <p className="text-xs text-muted-foreground">
                                {ev.employee_code}
                              </p>
                            )}
                          </div>,
                          <span key="d" className="text-muted-foreground">
                            {ev.date ? formatDate(ev.date) : "—"}
                          </span>,
                          <span key="ci" className="text-muted-foreground">
                            {ev.check_in ? formatTime(ev.check_in) : "—"}
                          </span>,
                          <span key="co" className="text-muted-foreground">
                            {ev.check_out ? formatTime(ev.check_out) : "—"}
                          </span>,
                          <Badge
                            key="s"
                            variant="outline"
                            className={
                              deviceStatusBadgeClass(ev.status) || "border-0"
                            }
                          >
                            {ev.status}
                          </Badge>,
                          <span
                            key="c"
                            className="tabular-nums text-muted-foreground"
                          >
                            {ev.confidence_score}%
                          </span>,
                        ],
                      }))}
                    />
                    <PaginationControls
                      meta={{ current_page: eventPage, last_page: eventPages }}
                      onPageChange={setEventPage}
                    />
                  </>
                )}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="commands" className="mt-4">
            <DeviceCommandsTab device={device} canUpdate={canUpdate} />
          </TabsContent>
        </Tabs>

        {/* Edit: the same form as the list's Edit and Register */}
        {editOpen && device && (
          <EditDeviceDialog
            device={device}
            onOpenChange={setEditOpen}
            onSubmit={update}
            isPending={updateMutation.isPending}
          />
        )}

        {/* Regenerate token: asks why, then shows the new address once */}
        <RegenerateTokenDialog
          devicePublicId={device.public_id}
          open={confirmRegen}
          onOpenChange={setConfirmRegen}
        />

        <DeleteDeviceDialog
          deviceName={deleteOpen ? device.name : null}
          pending={deleteMutation.isPending}
          onConfirm={remove}
          onClose={() => setDeleteOpen(false)}
        />
      </div>
    </RoleGate>
  );
}

function InfoCard({
  icon: Icon,
  label,
  value,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: string;
}) {
  return (
    <Card>
      <CardContent className="flex items-center gap-3 p-4">
        <Icon className="h-5 w-5 text-muted-foreground" />
        <div>
          <p className="text-xs text-muted-foreground">{label}</p>
          <p className="text-lg font-semibold text-foreground">{value}</p>
        </div>
      </CardContent>
    </Card>
  );
}

/** The shared device form, with the organisation's branches read only while it is open. */
function EditDeviceDialog({
  device,
  onOpenChange,
  onSubmit,
  isPending,
}: {
  device: Device;
  onOpenChange: (open: boolean) => void;
  onSubmit: (payload: DeviceUpdate) => void;
  isPending: boolean;
}) {
  const { t } = useT();
  const { data: branches } = branchesApi.useList();

  return (
    <DeviceFormDialog
      open
      onOpenChange={onOpenChange}
      title={t("devices_page.edit_device")}
      mode="edit"
      device={device}
      branches={branches?.data ?? []}
      onSubmit={onSubmit}
      isPending={isPending}
      submitLabel={t("leave_types_page.save_changes")}
    />
  );
}

function statusLabel(
  status: string,
  t: (key: string, fallback?: string) => string,
): string {
  const key = deviceStatusLabelKey(status);
  return key ? t(key) : status;
}

function syncStatusLabel(
  status: string,
  t: (key: string, fallback?: string) => string,
): string {
  const map: Record<string, string> = {
    success: t("device_detail_page.sync_success"),
    partial: t("device_detail_page.sync_partial"),
    failed: t("devices_dashboard_page.failed"),
    offline: t("devices_page.offline"),
    running: t("device_detail_page.sync_running"),
  };
  return map[status] ?? status;
}

/**
 * What to type on a terminal that sends to ETHR. Its HTTP listening screen
 * asks for the address in parts (server, port, protocol, path), not as one
 * URL, so the URL is shown the way the terminal wants it.
 */
