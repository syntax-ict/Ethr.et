"use client";

import { use, useState } from "react";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import Link from "next/link";
import {
  ArrowLeft,
  RefreshCw,
  Signal,
  Loader2,
  Pencil,
  Trash2,
  Copy,
  RotateCw,
  CheckCircle,
  XCircle,
  AlertTriangle,
  Clock,
  Wifi,
  WifiOff,
  Activity,
  ChevronLeft,
  ChevronRight,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { SimpleTable } from "@/components/shared/simple-table";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  DialogDescription,
} from "@/components/ui/dialog";

import { RoleGate } from "@/components/shared/role-gate";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

const ADAPTER_LABELS: Record<string, string> = {
  hikvision: "Hikvision",
  zkteco: "ZKTeco",
  suprema: "Suprema",
  mock: "Mock (Simulator)",
};

const STATUS_STYLES: Record<string, string> = {
  online: "bg-success-soft text-success-on-soft border-0",
  offline: "bg-muted text-muted-foreground border-0",
  error: "bg-destructive-soft text-destructive-on-soft border-0",
  pending: "bg-warning-soft text-warning-on-soft border-0",
};

const SYNC_STATUS_ICON: Record<string, React.ReactNode> = {
  success: <CheckCircle className="h-4 w-4 text-status-success" />,
  partial: <AlertTriangle className="h-4 w-4 text-status-warning" />,
  failed: <XCircle className="h-4 w-4 text-status-error" />,
  offline: <WifiOff className="h-4 w-4 text-muted-foreground" />,
  running: <Loader2 className="h-4 w-4 animate-spin text-status-info" />,
};

interface Device {
  public_id: string;
  name: string;
  location_description: string | null;
  serial_number: string | null;
  adapter_type: string;
  status: string;
  auto_sync: boolean;
  sync_interval_minutes: number;
  webhook_token: string | null;
  webhook_url: string | null;
  last_sync_at: string | null;
  branch?: { public_id: string; name: string } | null;
  branch_public_id: string | null;
  attendance_records_count?: number;
  sync_logs_count?: number;
  created_at: string;
  updated_at: string;
}

interface SyncLog {
  public_id: string;
  status: string;
  triggered_by: string;
  events_found: number;
  events_processed: number;
  events_failed: number;
  error_message: string | null;
  duration_ms: number | null;
  started_at: string;
  completed_at: string | null;
  created_at: string;
}

interface DeviceEvent {
  public_id: string;
  employee_name: string;
  employee_code: string | null;
  date: string;
  check_in: string | null;
  check_out: string | null;
  source: string;
  status: string;
  confidence_score: number;
  created_at: string;
}

export default function DeviceDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { t } = useT();
  // Punch times render in the tenant's timezone, not the browser's — §12g.
  const { formatTime } = useDateFormatters();
  const { id } = use(params);
  const queryClient = useQueryClient();
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [syncLogPage, setSyncLogPage] = useState(1);
  const [eventPage, setEventPage] = useState(1);

  const { data: device, isLoading } = useQuery<Device>({
    queryKey: ["devices", id],
    queryFn: async () => (await apiClient.get(`/devices/${id}`)).data,
  });

  const { data: syncLogs, isLoading: syncLogsLoading } = useQuery({
    queryKey: ["devices", id, "sync-logs", syncLogPage],
    queryFn: async () =>
      (
        await apiClient.get(`/devices/${id}/sync-logs`, {
          params: { page: syncLogPage, per_page: 10 },
        })
      ).data,
    enabled: !!device,
  });

  const { data: events, isLoading: eventsLoading } = useQuery({
    queryKey: ["devices", id, "events", eventPage],
    queryFn: async () =>
      (
        await apiClient.get(`/devices/${id}/events`, {
          params: { page: eventPage, per_page: 15 },
        })
      ).data,
    enabled: !!device,
  });

  const pullMutation = useMutation({
    mutationFn: async () => (await apiClient.post(`/devices/${id}/pull`)).data,
    onSuccess: () => {
      toast.success(t("devices_page.pull_initiated"));
      queryClient.invalidateQueries({ queryKey: ["devices", id] });
    },
    onError: () => toast.error(t("devices_page.pull_failed")),
  });

  const testMutation = useMutation({
    mutationFn: async () => (await apiClient.get(`/devices/${id}/status`)).data,
    onSuccess: (data) => {
      const s = (data as { status?: string })?.status;
      if (s === "online") toast.success(t("devices_page.online_responding"));
      else toast.error(`${t("devices_page.device_is")} ${s}`);
      queryClient.invalidateQueries({ queryKey: ["devices", id] });
    },
    onError: () => toast.error(t("devices_page.test_failed")),
  });

  const regenTokenMutation = useMutation({
    mutationFn: async () =>
      (await apiClient.post(`/devices/${id}/regenerate-token`)).data,
    onSuccess: () => {
      toast.success(t("device_detail_page.token_regenerated"));
      queryClient.invalidateQueries({ queryKey: ["devices", id] });
    },
    onError: () => toast.error(t("device_detail_page.regenerate_failed")),
  });

  const deleteMutation = useMutation({
    mutationFn: async () => {
      await apiClient.delete(`/devices/${id}`);
    },
    onSuccess: () => {
      toast.success(t("devices_page.deleted"));
      window.location.href = "/devices";
    },
    onError: () => toast.error(t("devices_page.delete_failed")),
  });

  const updateMutation = useMutation({
    mutationFn: async (data: Record<string, unknown>) =>
      (await apiClient.put(`/devices/${id}`, data)).data,
    onSuccess: () => {
      toast.success(t("devices_page.updated"));
      setEditOpen(false);
      queryClient.invalidateQueries({ queryKey: ["devices", id] });
    },
    onError: () => toast.error(t("device_detail_page.update_failed")),
  });

  if (isLoading) {
    return (
      <RoleGate minRole="hr_admin">
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

  if (!device) {
    return (
      <div className="py-16 text-center text-muted-foreground">
        {t("device_detail_page.not_found")}
      </div>
    );
  }

  const isOnline = device.status === "online";
  const syncLogItems: SyncLog[] = syncLogs?.data ?? [];
  const eventItems: DeviceEvent[] = events?.data ?? [];

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="sm" asChild>
            <Link href="/devices">
              <ArrowLeft className="mr-2 h-4 w-4" />{" "}
              {t("devices_dashboard_page.all_devices")}
            </Link>
          </Button>
        </div>

        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold text-foreground">
                {device.name}
              </h1>
              <Badge
                variant="outline"
                className={STATUS_STYLES[device.status] ?? ""}
              >
                <span
                  className={`mr-1.5 inline-block h-1.5 w-1.5 rounded-full ${isOnline ? "bg-success animate-pulse" : device.status === "error" ? "bg-destructive" : "bg-muted-foreground"}`}
                />
                {statusLabel(device.status, t)}
              </Badge>
            </div>
            <p className="mt-1 text-sm text-muted-foreground">
              {device.adapter_type === "mock"
                ? t("devices_page.mock_simulator")
                : (ADAPTER_LABELS[device.adapter_type] ?? device.adapter_type)}
              {device.location_description &&
                ` — ${device.location_description}`}
              {device.branch?.name && ` — ${device.branch.name}`}
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => testMutation.mutate()}
              disabled={testMutation.isPending}
            >
              {testMutation.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Signal className="mr-2 h-4 w-4" />
              )}
              {t("devices_page.test")}
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => pullMutation.mutate()}
              disabled={pullMutation.isPending}
            >
              {pullMutation.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <RefreshCw className="mr-2 h-4 w-4" />
              )}
              {t("devices_page.pull")}
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setEditOpen(true)}
            >
              <Pencil className="mr-2 h-4 w-4" /> {t("common.edit")}
            </Button>
            <Button
              variant="destructive"
              size="sm"
              onClick={() => setDeleteOpen(true)}
            >
              <Trash2 className="mr-2 h-4 w-4" /> {t("common.delete")}
            </Button>
          </div>
        </div>

        {/* Info Cards */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
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
            label={t("devices_dashboard_page.last_sync")}
            value={
              device.last_sync_at
                ? timeAgo(device.last_sync_at, t)
                : t("devices_page.never")
            }
          />
          <InfoCard
            icon={isOnline ? Wifi : WifiOff}
            label={t("device_detail_page.auto_sync")}
            value={
              device.auto_sync
                ? `${t("devices_page.every")} ${device.sync_interval_minutes}m`
                : t("device_detail_page.disabled")
            }
          />
        </div>

        {/* Webhook URL */}
        {device.webhook_token && device.adapter_type !== "mock" && (
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-sm">
                {t("device_detail_page.webhook_config")}
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-3">
                <div>
                  <Label className="text-xs text-muted-foreground">
                    {t("device_detail_page.webhook_url_hint")}
                  </Label>
                  <div className="mt-1 flex items-center gap-2">
                    <code className="flex-1 rounded border bg-muted/50 px-3 py-2 text-xs font-mono break-all">
                      {device.webhook_url ?? "N/A"}
                    </code>
                    <Button
                      variant="outline"
                      size="icon"
                      className="h-8 w-8 shrink-0"
                      // Icon-only, so the Copy glyph is the entire content and
                      // a screen reader announced an unnamed "button".
                      aria-label={t(
                        "device_detail_page.copy_webhook_url",
                        "Copy webhook URL",
                      )}
                      onClick={() => {
                        navigator.clipboard.writeText(device.webhook_url ?? "");
                        toast.success(t("attendance.kiosks_page.copied"));
                      }}
                    >
                      <Copy className="h-3 w-3" />
                    </Button>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => regenTokenMutation.mutate()}
                    disabled={regenTokenMutation.isPending}
                  >
                    <RotateCw className="mr-2 h-3 w-3" />
                    {t("device_detail_page.regenerate_token")}
                  </Button>
                  <span className="text-xs text-muted-foreground">
                    {t("device_detail_page.regenerate_hint")}
                  </span>
                </div>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Tabs: Sync History + Events */}
        <Tabs defaultValue="syncs">
          <TabsList>
            <TabsTrigger value="syncs">
              {t("device_detail_page.sync_history")}
            </TabsTrigger>
            <TabsTrigger value="events">
              {t("device_detail_page.attendance_events")}
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
                ) : syncLogItems.length === 0 ? (
                  <div className="py-12 text-center text-sm text-muted-foreground">
                    {t("device_detail_page.no_sync_history")}
                  </div>
                ) : (
                  <>
                    <SimpleTable
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
                          <div
                            key="s"
                            className="flex items-center gap-2 text-sm"
                          >
                            {SYNC_STATUS_ICON[log.status] ?? null}
                            <span className="capitalize">
                              {syncStatusLabel(log.status, t)}
                            </span>
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
                            {timeAgo(log.started_at, t)}
                          </span>,
                        ],
                      }))}
                    />
                    {syncLogs?.meta?.last_page > 1 && (
                      <div className="flex items-center justify-between border-t p-3">
                        <span className="text-xs text-muted-foreground">
                          {t("device_detail_page.page")} {syncLogPage}
                        </span>
                        <div className="flex gap-1">
                          <Button
                            variant="outline"
                            size="icon"
                            className="h-7 w-7"
                            aria-label={t(
                              "common.previous_page",
                              "Previous page",
                            )}
                            disabled={syncLogPage <= 1}
                            onClick={() => setSyncLogPage((p) => p - 1)}
                          >
                            <ChevronLeft className="h-4 w-4" />
                          </Button>
                          <Button
                            variant="outline"
                            size="icon"
                            className="h-7 w-7"
                            aria-label={t("common.next_page", "Next page")}
                            disabled={syncLogPage >= syncLogs.meta.last_page}
                            onClick={() => setSyncLogPage((p) => p + 1)}
                          >
                            <ChevronRight className="h-4 w-4" />
                          </Button>
                        </div>
                      </div>
                    )}
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
                ) : eventItems.length === 0 ? (
                  <div className="py-12 text-center text-sm text-muted-foreground">
                    {t("device_detail_page.no_events")}
                  </div>
                ) : (
                  <>
                    <SimpleTable
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
                            {ev.date}
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
                            className={STATUS_STYLES[ev.status] ?? "border-0"}
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
                    {events?.meta?.last_page > 1 && (
                      <div className="flex items-center justify-between border-t p-3">
                        <span className="text-xs text-muted-foreground">
                          {t("device_detail_page.page")} {eventPage}
                        </span>
                        <div className="flex gap-1">
                          <Button
                            variant="outline"
                            size="icon"
                            className="h-7 w-7"
                            aria-label={t(
                              "common.previous_page",
                              "Previous page",
                            )}
                            disabled={eventPage <= 1}
                            onClick={() => setEventPage((p) => p - 1)}
                          >
                            <ChevronLeft className="h-4 w-4" />
                          </Button>
                          <Button
                            variant="outline"
                            size="icon"
                            className="h-7 w-7"
                            aria-label={t("common.next_page", "Next page")}
                            disabled={eventPage >= events.meta.last_page}
                            onClick={() => setEventPage((p) => p + 1)}
                          >
                            <ChevronRight className="h-4 w-4" />
                          </Button>
                        </div>
                      </div>
                    )}
                  </>
                )}
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>

        {/* Edit Dialog */}
        {editOpen && device && (
          <EditDeviceDialog
            device={device}
            open={editOpen}
            onOpenChange={setEditOpen}
            onSubmit={(data) => updateMutation.mutate(data)}
            isPending={updateMutation.isPending}
          />
        )}

        {/* Delete Confirmation */}
        <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{t("devices_page.delete_device")}</DialogTitle>
              <DialogDescription>
                {t("devices_page.delete_confirm_prefix")}{" "}
                <strong>{device.name}</strong>?{" "}
                {t("device_detail_page.delete_desc_suffix")}
              </DialogDescription>
            </DialogHeader>
            <DialogFooter>
              <Button variant="outline" onClick={() => setDeleteOpen(false)}>
                {t("common.cancel")}
              </Button>
              <Button
                variant="destructive"
                onClick={() => deleteMutation.mutate()}
                disabled={deleteMutation.isPending}
              >
                {deleteMutation.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("common.delete")}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
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

function EditDeviceDialog({
  device,
  open,
  onOpenChange,
  onSubmit,
  isPending,
}: {
  device: Device;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSubmit: (data: Record<string, unknown>) => void;
  isPending: boolean;
}) {
  const { t } = useT();
  const [form, setForm] = useState({
    name: device.name,
    location_description: device.location_description ?? "",
    serial_number: device.serial_number ?? "",
    auto_sync: device.auto_sync,
    sync_interval_minutes: String(device.sync_interval_minutes),
  });

  const set = (key: string, value: string | boolean) =>
    setForm((p) => ({ ...p, [key]: value }));

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t("devices_page.edit_device")}</DialogTitle>
        </DialogHeader>
        <form
          onSubmit={(e) => {
            e.preventDefault();
            onSubmit({
              name: form.name,
              location_description: form.location_description || null,
              serial_number: form.serial_number || null,
              auto_sync: form.auto_sync,
              sync_interval_minutes:
                parseInt(form.sync_interval_minutes, 10) || 5,
            });
          }}
          className="space-y-4"
        >
          <div>
            <Label htmlFor="device-name">{t("devices_page.device_name")}</Label>
            <Input
              id="device-name"
              value={form.name}
              onChange={(e) => set("name", e.target.value)}
              required
              className="mt-1"
            />
          </div>
          <div>
            <Label htmlFor="location-description">
              {t("device_detail_page.location_description")}
            </Label>
            <Input
              id="location-description"
              value={form.location_description}
              onChange={(e) => set("location_description", e.target.value)}
              placeholder={t("device_detail_page.location_placeholder")}
              className="mt-1"
            />
          </div>
          <div>
            <Label htmlFor="serial-number">
              {t("devices_page.serial_number")}
            </Label>
            <Input
              id="serial-number"
              value={form.serial_number}
              onChange={(e) => set("serial_number", e.target.value)}
              className="mt-1"
            />
          </div>
          <div className="flex items-center justify-between rounded-lg border p-3">
            <div>
              <p className="text-sm font-medium">
                {t("device_detail_page.auto_sync")}
              </p>
              <p className="text-xs text-muted-foreground">
                {t("device_detail_page.auto_sync_hint")}
              </p>
            </div>
            <Switch
              aria-label={t("device_detail_page.auto_sync")}
              checked={form.auto_sync}
              onCheckedChange={(v) => set("auto_sync", v)}
            />
          </div>
          {form.auto_sync && (
            <div>
              <Label htmlFor="sync-interval">
                {t("device_detail_page.sync_interval")}
              </Label>
              <Select
                value={form.sync_interval_minutes}
                onValueChange={(v) => set("sync_interval_minutes", v)}
              >
                <SelectTrigger id="sync-interval" className="mt-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="1">
                    {t("device_detail_page.every_1_min")}
                  </SelectItem>
                  <SelectItem value="5">
                    {t("device_detail_page.every_5_min")}
                  </SelectItem>
                  <SelectItem value="10">
                    {t("device_detail_page.every_10_min")}
                  </SelectItem>
                  <SelectItem value="15">
                    {t("device_detail_page.every_15_min")}
                  </SelectItem>
                  <SelectItem value="30">
                    {t("device_detail_page.every_30_min")}
                  </SelectItem>
                  <SelectItem value="60">
                    {t("device_detail_page.every_1_hour")}
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>
          )}
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
            >
              {t("common.cancel")}
            </Button>
            <Button type="submit" disabled={isPending}>
              {isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              {t("leave_types_page.save_changes")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function statusLabel(
  status: string,
  t: (key: string, fallback?: string) => string,
): string {
  const map: Record<string, string> = {
    online: t("devices_page.online"),
    offline: t("devices_page.offline"),
    error: t("devices_page.error"),
    pending: t("devices_page.pending"),
  };
  return map[status] ?? status;
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

function timeAgo(
  iso: string,
  t: (key: string, fallback?: string) => string,
): string {
  const diffMs = Date.now() - new Date(iso).getTime();
  const m = Math.floor(diffMs / 60000);
  if (m < 1) return t("devices_dashboard_page.just_now");
  if (m < 60) return `${m}${t("devices_dashboard_page.m_ago")}`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}${t("devices_dashboard_page.h_ago")}`;
  return `${Math.floor(h / 24)}${t("devices_dashboard_page.d_ago")}`;
}
