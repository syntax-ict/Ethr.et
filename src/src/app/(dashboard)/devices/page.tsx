"use client";

import { useState } from "react";
import Link from "next/link";
import {
  Fingerprint,
  Plus,
  Wifi,
  WifiOff,
  RefreshCw,
  Loader2,
  MoreVertical,
  Pencil,
  Trash2,
  Activity,
  Signal,
  AlertTriangle,
  Users,
  History,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  DialogDescription,
} from "@/components/ui/dialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { RoleGate } from "@/components/shared/role-gate";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";

import {
  DeviceEnrollmentsDialog,
  ImportHistoryDialog,
} from "@/features/devices/components/device-workforce-dialogs";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

interface Device {
  public_id: string;
  name: string;
  location_description: string | null;
  serial_number: string | null;
  adapter_type: string;
  status: string;
  auto_sync: boolean;
  sync_interval_minutes: number;
  last_sync_at: string | null;
  branch?: { public_id: string; name: string } | null;
  branch_public_id: string | null;
  attendance_records_count?: number;
  sync_logs_count?: number;
  created_at: string;
  updated_at: string;
}

interface DeviceFormData {
  name: string;
  adapter_type: string;
  serial_number: string;
  branch_public_id: string;
  ip: string;
  port: string;
  username: string;
  password: string;
  api_key: string;
}

const EMPTY_FORM: DeviceFormData = {
  name: "",
  adapter_type: "mock",
  serial_number: "",
  branch_public_id: "",
  ip: "",
  port: "80",
  username: "",
  password: "",
  api_key: "",
};

const ADAPTER_LABELS: Record<string, string> = {
  hikvision: "Hikvision",
  zkteco: "ZKTeco",
  suprema: "Suprema",
  mock: "Mock (Simulator)",
};

function buildPayload(form: DeviceFormData) {
  const isMock = form.adapter_type === "mock";
  return {
    name: form.name,
    adapter_type: form.adapter_type,
    serial_number: form.serial_number || null,
    branch_public_id: form.branch_public_id,
    connection_config: {
      ip: isMock ? "127.0.0.1" : form.ip,
      port: isMock ? 0 : parseInt(form.port, 10) || 80,
      username: form.username || null,
      password: form.password || null,
      api_key: form.api_key || null,
    },
  };
}

export default function DevicesPage() {
  const { t } = useT();
  const queryClient = useQueryClient();
  const [createOpen, setCreateOpen] = useState(false);
  const [editDevice, setEditDevice] = useState<Device | null>(null);
  const [deleteDevice, setDeleteDevice] = useState<Device | null>(null);
  const [discoverDevice, setDiscoverDevice] = useState<Device | null>(null);
  const [historyDevice, setHistoryDevice] = useState<Device | null>(null);
  const [form, setForm] = useState<DeviceFormData>({ ...EMPTY_FORM });
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");

  const { data, isLoading } = useQuery({
    queryKey: ["devices", search, statusFilter],
    queryFn: async () => {
      const params: Record<string, string> = {};
      if (search) params.search = search;
      if (statusFilter !== "all") params["filter[status]"] = statusFilter;
      const { data } = await apiClient.get("/devices", { params });
      return data;
    },
  });

  const { data: branches } = useQuery({
    queryKey: ["org", "branches"],
    queryFn: async () => (await apiClient.get("/organization/branches")).data,
  });

  const createMutation = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post("/devices", buildPayload(form));
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["devices"] });
      toast.success(t("devices_page.registered"));
      setCreateOpen(false);
      setForm({ ...EMPTY_FORM });
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { detail?: string } } })
        ?.response?.data?.detail;
      toast.error(msg || t("devices_page.add_failed"));
    },
  });

  const updateMutation = useMutation({
    mutationFn: async () => {
      if (!editDevice) return;
      const { data } = await apiClient.put(
        `/devices/${editDevice.public_id}`,
        buildPayload(form),
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["devices"] });
      toast.success(t("devices_page.updated"));
      setEditDevice(null);
    },
    onError: () => toast.error(t("devices_page.update_failed")),
  });

  const deleteMutation = useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`/devices/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["devices"] });
      toast.success(t("devices_page.deleted"));
      setDeleteDevice(null);
    },
    onError: () => toast.error(t("devices_page.delete_failed")),
  });

  const pullMutation = useMutation({
    mutationFn: async (id: string) => {
      const { data } = await apiClient.post(`/devices/${id}/pull`);
      return data;
    },
    onSuccess: () => toast.success(t("devices_page.pull_initiated")),
    onError: () => toast.error(t("devices_page.pull_failed")),
  });

  const syncAllMutation = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post("/devices/sync-all");
      return data;
    },
    onSuccess: (data) => {
      const d = data as { dispatched?: number };
      toast.success(
        `${t("devices_page.sync_dispatched_prefix")} ${d.dispatched ?? 0} ${t("devices_page.device_s")}`,
      );
      queryClient.invalidateQueries({ queryKey: ["devices"] });
    },
    onError: () => toast.error(t("devices_page.bulk_sync_failed")),
  });

  const testMutation = useMutation({
    mutationFn: async (id: string) => {
      const { data } = await apiClient.get(`/devices/${id}/status`);
      return data;
    },
    onSuccess: (data) => {
      const status = (data as { status?: string })?.status;
      if (status === "online") {
        toast.success(t("devices_page.online_responding"));
      } else {
        toast.error(`${t("devices_page.device_is")} ${status}`);
      }
      queryClient.invalidateQueries({ queryKey: ["devices"] });
    },
    onError: () => toast.error(t("devices_page.test_failed")),
  });

  function openEdit(device: Device) {
    setForm({
      name: device.name,
      adapter_type: device.adapter_type,
      serial_number: device.serial_number ?? "",
      branch_public_id: device.branch_public_id ?? "",
      ip: "",
      port: "80",
      username: "",
      password: "",
      api_key: "",
    });
    setEditDevice(device);
  }

  const devices: Device[] = data?.data ?? [];

  const isMockAdapter = form.adapter_type === "mock";

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("devices_page.title")}
          description={t("devices_page.description")}
          actions={
            <div className="flex items-center gap-2">
              <Button variant="outline" size="sm" asChild>
                <Link href="/devices/dashboard">
                  <Activity className="mr-2 h-4 w-4" />{" "}
                  {t("devices_page.health_dashboard")}
                </Link>
              </Button>
              <Button
                variant="outline"
                size="sm"
                onClick={() => syncAllMutation.mutate()}
                disabled={syncAllMutation.isPending || devices.length === 0}
              >
                {syncAllMutation.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <RefreshCw className="mr-2 h-4 w-4" />
                )}
                {t("devices_page.sync_all")}
              </Button>
              <Button
                onClick={() => {
                  setForm({ ...EMPTY_FORM });
                  setCreateOpen(true);
                }}
              >
                <Plus className="mr-2 h-4 w-4" /> {t("devices_page.add_device")}
              </Button>
            </div>
          }
        />

        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
          <Input
            placeholder={t("devices_page.search_placeholder")}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="max-w-xs"
          />
          <Select value={statusFilter} onValueChange={setStatusFilter}>
            <SelectTrigger
              className="w-[140px]"
              aria-label={t("common.status")}
            >
              <SelectValue placeholder={t("common.status")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">
                {t("devices_page.all_status")}
              </SelectItem>
              <SelectItem value="online">{t("devices_page.online")}</SelectItem>
              <SelectItem value="offline">
                {t("devices_page.offline")}
              </SelectItem>
              <SelectItem value="error">{t("devices_page.error")}</SelectItem>
              <SelectItem value="pending">
                {t("devices_page.pending")}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        {isLoading ? (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {Array.from({ length: 6 }).map((_, i) => (
              <Skeleton key={i} className="h-48" />
            ))}
          </div>
        ) : devices.length === 0 ? (
          <EmptyState
            icon={Fingerprint}
            title={t("devices_page.no_devices")}
            description={t("devices_page.no_devices_desc")}
            action={
              <Button
                onClick={() => {
                  setForm({ ...EMPTY_FORM });
                  setCreateOpen(true);
                }}
              >
                <Plus className="mr-2 h-4 w-4" /> {t("devices_page.add_device")}
              </Button>
            }
          />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {devices.map((d) => (
              <DeviceCard
                key={d.public_id}
                device={d}
                onPull={() => pullMutation.mutate(d.public_id)}
                onTest={() => testMutation.mutate(d.public_id)}
                onEdit={() => openEdit(d)}
                onDelete={() => setDeleteDevice(d)}
                onDiscover={() => setDiscoverDevice(d)}
                onImportHistory={() => setHistoryDevice(d)}
                pulling={pullMutation.isPending}
                testing={testMutation.isPending}
              />
            ))}
          </div>
        )}

        {/* Create Dialog */}
        <DeviceFormDialog
          open={createOpen}
          onOpenChange={setCreateOpen}
          title={t("devices_page.register_device")}
          form={form}
          setForm={setForm}
          branches={branches?.data ?? []}
          isMock={isMockAdapter}
          onSubmit={(e) => {
            e.preventDefault();
            createMutation.mutate();
          }}
          isPending={createMutation.isPending}
          submitLabel={t("devices_page.register")}
        />

        {/* Edit Dialog */}
        <DeviceFormDialog
          open={!!editDevice}
          onOpenChange={(open) => {
            if (!open) setEditDevice(null);
          }}
          title={t("devices_page.edit_device")}
          form={form}
          setForm={setForm}
          branches={branches?.data ?? []}
          isMock={isMockAdapter}
          onSubmit={(e) => {
            e.preventDefault();
            updateMutation.mutate();
          }}
          isPending={updateMutation.isPending}
          submitLabel={t("leave_types_page.save_changes")}
        />

        {/* Delete Confirmation */}
        <Dialog
          open={!!deleteDevice}
          onOpenChange={(open) => {
            if (!open) setDeleteDevice(null);
          }}
        >
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{t("devices_page.delete_device")}</DialogTitle>
              <DialogDescription>
                {t("devices_page.delete_confirm_prefix")}{" "}
                <strong>{deleteDevice?.name}</strong>?{" "}
                {t("devices_page.delete_confirm_suffix")}
              </DialogDescription>
            </DialogHeader>
            <DialogFooter>
              <Button variant="outline" onClick={() => setDeleteDevice(null)}>
                {t("common.cancel")}
              </Button>
              <Button
                variant="destructive"
                onClick={() =>
                  deleteDevice && deleteMutation.mutate(deleteDevice.public_id)
                }
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

        {/* Workforce discovery + attendance-history backfill */}
        <DeviceEnrollmentsDialog
          devicePublicId={discoverDevice?.public_id ?? null}
          deviceName={discoverDevice?.name}
          onClose={() => setDiscoverDevice(null)}
        />
        <ImportHistoryDialog
          devicePublicId={historyDevice?.public_id ?? null}
          deviceName={historyDevice?.name}
          onClose={() => setHistoryDevice(null)}
        />
      </div>
    </RoleGate>
  );
}

function DeviceCard({
  device,
  onPull,
  onTest,
  onEdit,
  onDelete,
  onDiscover,
  onImportHistory,
  pulling,
  testing,
}: {
  device: Device;
  onPull: () => void;
  onTest: () => void;
  onEdit: () => void;
  onDelete: () => void;
  onDiscover: () => void;
  onImportHistory: () => void;
  pulling: boolean;
  testing: boolean;
}) {
  const { t } = useT();
  const isOnline = device.status === "online";
  const isError = device.status === "error";

  const statusIcon = isOnline ? (
    <Wifi className="h-5 w-5 text-success" />
  ) : isError ? (
    <AlertTriangle className="h-5 w-5 text-destructive" />
  ) : (
    <WifiOff className="h-5 w-5 text-muted-foreground" />
  );

  const statusBg = isOnline
    ? "bg-success-soft"
    : isError
      ? "bg-destructive-soft"
      : "bg-neutral-soft";

  return (
    <Card>
      <CardContent className="p-4">
        <div className="flex items-start justify-between">
          <div className="flex items-center gap-3">
            <div
              className={`flex h-10 w-10 items-center justify-center rounded-xl ${statusBg}`}
            >
              {statusIcon}
            </div>
            <div>
              <Link
                href={`/devices/${device.public_id}`}
                className="font-semibold text-foreground hover:underline"
              >
                {device.name}
              </Link>
              <p className="text-xs text-muted-foreground capitalize">
                {device.adapter_type === "mock"
                  ? t("devices_page.mock_simulator")
                  : (ADAPTER_LABELS[device.adapter_type] ??
                    device.adapter_type)}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-1">
            <StatusBadge status={device.status} />
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-7 w-7"
                  aria-label={t("common.actions", "Actions")}
                >
                  <MoreVertical className="h-4 w-4" aria-hidden="true" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={onTest} disabled={testing}>
                  <Signal className="mr-2 h-4 w-4" />{" "}
                  {t("devices_page.test_connection")}
                </DropdownMenuItem>
                <DropdownMenuItem onClick={onPull} disabled={pulling}>
                  <RefreshCw className="mr-2 h-4 w-4" />{" "}
                  {t("devices_page.pull_records")}
                </DropdownMenuItem>
                <DropdownMenuItem onClick={onDiscover}>
                  <Users className="mr-2 h-4 w-4" />{" "}
                  {t("devices_page.discover_users", "Discover enrolled users")}
                </DropdownMenuItem>
                <DropdownMenuItem onClick={onImportHistory}>
                  <History className="mr-2 h-4 w-4" />{" "}
                  {t(
                    "devices_page.import_history",
                    "Import attendance history",
                  )}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem onClick={onEdit}>
                  <Pencil className="mr-2 h-4 w-4" /> {t("common.edit")}
                </DropdownMenuItem>
                <DropdownMenuItem
                  onClick={onDelete}
                  className="text-destructive"
                >
                  <Trash2 className="mr-2 h-4 w-4" /> {t("common.delete")}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>

        <div className="mt-4 space-y-1.5 text-xs text-muted-foreground">
          {device.serial_number && (
            <p>
              {t("devices_page.sn")}:{" "}
              <span className="font-mono">{device.serial_number}</span>
            </p>
          )}
          {device.branch?.name && (
            <p>
              {t("attendance.kiosks_page.branch").replace(" *", "")}:{" "}
              {device.branch.name}
            </p>
          )}
          {device.attendance_records_count !== undefined && (
            <p>
              {t("devices_page.records")}:{" "}
              {device.attendance_records_count.toLocaleString()}
            </p>
          )}
          <p>
            {t("devices_page.sync")}:{" "}
            {device.auto_sync
              ? `${t("devices_page.every")} ${device.sync_interval_minutes}m`
              : t("devices_page.manual_only")}
          </p>
          <p>
            {t("attendance.kiosks_page.last_active")}:{" "}
            {device.last_sync_at
              ? new Date(device.last_sync_at).toLocaleString()
              : t("devices_page.never")}
          </p>
        </div>

        <div className="mt-3 grid grid-cols-2 gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={onTest}
            disabled={testing}
          >
            {testing ? (
              <Loader2 className="mr-1 h-3 w-3 animate-spin" />
            ) : (
              <Signal className="mr-1 h-3 w-3" />
            )}
            {t("devices_page.test")}
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={onPull}
            disabled={pulling}
          >
            {pulling ? (
              <Loader2 className="mr-1 h-3 w-3 animate-spin" />
            ) : (
              <RefreshCw className="mr-1 h-3 w-3" />
            )}
            {t("devices_page.pull")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

function StatusBadge({ status }: { status: string }) {
  const { t } = useT();
  const labels: Record<string, string> = {
    online: t("devices_page.online"),
    offline: t("devices_page.offline"),
    error: t("devices_page.error"),
    pending: t("devices_page.pending"),
  };
  const styles: Record<string, string> = {
    online: "bg-success-soft text-success-on-soft border-0",
    offline: "bg-neutral-soft text-neutral-on-soft border-0",
    error: "bg-destructive-soft text-destructive-on-soft border-0",
    pending: "bg-warning-soft text-warning-on-soft border-0",
  };

  const dots: Record<string, string> = {
    online: "bg-success animate-pulse",
    offline: "bg-muted-foreground",
    error: "bg-destructive",
    pending: "bg-warning",
  };

  return (
    <Badge variant="outline" className={styles[status] ?? ""}>
      <span
        className={`mr-1.5 inline-block h-1.5 w-1.5 rounded-full ${dots[status] ?? "bg-muted-foreground"}`}
      />
      {labels[status] ?? status}
    </Badge>
  );
}

function DeviceFormDialog({
  open,
  onOpenChange,
  title,
  form,
  setForm,
  branches,
  isMock,
  onSubmit,
  isPending,
  submitLabel,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  form: DeviceFormData;
  setForm: React.Dispatch<React.SetStateAction<DeviceFormData>>;
  branches: { public_id: string; name: string }[];
  isMock: boolean;
  onSubmit: (e: React.FormEvent) => void;
  isPending: boolean;
  submitLabel: string;
}) {
  const { t } = useT();
  const set = (key: keyof DeviceFormData, value: string) =>
    setForm((p) => ({ ...p, [key]: value }));

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
        </DialogHeader>
        <form onSubmit={onSubmit} className="space-y-4">
          <div>
            <Label htmlFor="device-name">{t("devices_page.device_name")}</Label>
            <Input
              id="device-name"
              value={form.name}
              onChange={(e) => set("name", e.target.value)}
              required
              placeholder={t("devices_page.device_name_placeholder")}
              className="mt-1"
            />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label htmlFor="adapter-type">
                {t("devices_page.adapter_type")}
              </Label>
              <Select
                value={form.adapter_type}
                onValueChange={(v) => set("adapter_type", v)}
              >
                <SelectTrigger id="adapter-type" className="mt-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="hikvision">Hikvision</SelectItem>
                  <SelectItem value="zkteco">ZKTeco</SelectItem>
                  <SelectItem value="suprema">Suprema</SelectItem>
                  <SelectItem value="mock">
                    {t("devices_page.mock_simulator")}
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label htmlFor="serial-number">
                {t("devices_page.serial_number")}
              </Label>
              <Input
                id="serial-number"
                value={form.serial_number}
                onChange={(e) => set("serial_number", e.target.value)}
                placeholder={t("leave_page.optional")}
                className="mt-1"
              />
            </div>
          </div>

          {isMock && (
            <div className="rounded-lg border border-warning-edge bg-warning-soft p-3 text-sm text-warning-on-soft">
              {t("devices_page.mock_hint")}
            </div>
          )}

          {!isMock && (
            <div className="space-y-4 rounded-lg border p-4">
              <p className="text-xs font-medium text-muted-foreground uppercase">
                {t("devices_page.connection_settings")}
              </p>
              <div className="grid grid-cols-3 gap-3">
                <div className="col-span-2">
                  <Label htmlFor="ip-address">
                    {t("devices_page.ip_address")}
                  </Label>
                  <Input
                    id="ip-address"
                    value={form.ip}
                    onChange={(e) => set("ip", e.target.value)}
                    required
                    placeholder="192.168.1.100"
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label htmlFor="port">{t("devices_page.port")}</Label>
                  <Input
                    id="port"
                    value={form.port}
                    onChange={(e) => set("port", e.target.value)}
                    required
                    type="number"
                    min={1}
                    max={65535}
                    className="mt-1"
                  />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <Label htmlFor="username">{t("devices_page.username")}</Label>
                  <Input
                    id="username"
                    value={form.username}
                    onChange={(e) => set("username", e.target.value)}
                    placeholder={t("leave_page.optional")}
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label htmlFor="password">{t("auth.password")}</Label>
                  <Input
                    id="password"
                    type="password"
                    value={form.password}
                    onChange={(e) => set("password", e.target.value)}
                    placeholder={t("leave_page.optional")}
                    className="mt-1"
                  />
                </div>
              </div>
              {form.adapter_type === "suprema" && (
                <div>
                  <Label htmlFor="api-key">{t("devices_page.api_key")}</Label>
                  <Input
                    id="api-key"
                    value={form.api_key}
                    onChange={(e) => set("api_key", e.target.value)}
                    placeholder={t("devices_page.api_key_placeholder")}
                    className="mt-1"
                  />
                </div>
              )}
            </div>
          )}

          {branches.length > 0 && (
            <div>
              <Label htmlFor="branch">
                {t("attendance.kiosks_page.branch").replace(" *", "")}
              </Label>
              <Select
                value={form.branch_public_id}
                onValueChange={(v) => set("branch_public_id", v)}
              >
                <SelectTrigger id="branch" className="mt-1">
                  <SelectValue
                    placeholder={t("attendance.kiosks_page.select_branch")}
                  />
                </SelectTrigger>
                <SelectContent>
                  {branches.map((b) => (
                    <SelectItem key={b.public_id} value={b.public_id}>
                      {b.name}
                    </SelectItem>
                  ))}
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
              {submitLabel}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
