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
import { useDeviceDashboard } from "@/features/devices/api";
import { apiClient } from "@/api/client";
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
  const queryClient = useQueryClient();
  const [createOpen, setCreateOpen] = useState(false);
  const [editDevice, setEditDevice] = useState<Device | null>(null);
  const [deleteDevice, setDeleteDevice] = useState<Device | null>(null);
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
      toast.success("Device registered");
      setCreateOpen(false);
      setForm({ ...EMPTY_FORM });
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { detail?: string } } })
        ?.response?.data?.detail;
      toast.error(msg || "Failed to add device");
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
      toast.success("Device updated");
      setEditDevice(null);
    },
    onError: () => toast.error("Failed to update device"),
  });

  const deleteMutation = useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`/devices/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["devices"] });
      toast.success("Device deleted");
      setDeleteDevice(null);
    },
    onError: () => toast.error("Failed to delete device"),
  });

  const pullMutation = useMutation({
    mutationFn: async (id: string) => {
      const { data } = await apiClient.post(`/devices/${id}/pull`);
      return data;
    },
    onSuccess: () => toast.success("Pull initiated — events will sync shortly"),
    onError: () => toast.error("Pull failed"),
  });

  const syncAllMutation = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post("/devices/sync-all");
      return data;
    },
    onSuccess: (data) => {
      const d = data as { dispatched?: number };
      toast.success(`Sync dispatched for ${d.dispatched ?? 0} device(s)`);
      queryClient.invalidateQueries({ queryKey: ["devices"] });
    },
    onError: () => toast.error("Bulk sync failed"),
  });

  const testMutation = useMutation({
    mutationFn: async (id: string) => {
      const { data } = await apiClient.get(`/devices/${id}/status`);
      return data;
    },
    onSuccess: (data) => {
      const status = (data as { status?: string })?.status;
      if (status === "online") {
        toast.success("Device is online and responding");
      } else {
        toast.error(`Device is ${status}`);
      }
      queryClient.invalidateQueries({ queryKey: ["devices"] });
    },
    onError: () => toast.error("Connection test failed"),
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
          title="Devices"
          description="Biometric attendance devices and integrations"
          actions={
            <div className="flex items-center gap-2">
              <Button variant="outline" size="sm" asChild>
                <Link href="/devices/dashboard">
                  <Activity className="mr-2 h-4 w-4" /> Health Dashboard
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
                Sync All
              </Button>
              <Button
                onClick={() => {
                  setForm({ ...EMPTY_FORM });
                  setCreateOpen(true);
                }}
              >
                <Plus className="mr-2 h-4 w-4" /> Add Device
              </Button>
            </div>
          }
        />

        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
          <Input
            placeholder="Search devices..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="max-w-xs"
          />
          <Select value={statusFilter} onValueChange={setStatusFilter}>
            <SelectTrigger className="w-[140px]">
              <SelectValue placeholder="Status" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Status</SelectItem>
              <SelectItem value="online">Online</SelectItem>
              <SelectItem value="offline">Offline</SelectItem>
              <SelectItem value="error">Error</SelectItem>
              <SelectItem value="pending">Pending</SelectItem>
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
            title="No devices registered"
            description="Add a biometric device or a mock simulator to start syncing attendance"
            action={
              <Button
                onClick={() => {
                  setForm({ ...EMPTY_FORM });
                  setCreateOpen(true);
                }}
              >
                <Plus className="mr-2 h-4 w-4" /> Add Device
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
          title="Register Device"
          form={form}
          setForm={setForm}
          branches={branches?.data ?? []}
          isMock={isMockAdapter}
          onSubmit={(e) => {
            e.preventDefault();
            createMutation.mutate();
          }}
          isPending={createMutation.isPending}
          submitLabel="Register"
        />

        {/* Edit Dialog */}
        <DeviceFormDialog
          open={!!editDevice}
          onOpenChange={(open) => {
            if (!open) setEditDevice(null);
          }}
          title="Edit Device"
          form={form}
          setForm={setForm}
          branches={branches?.data ?? []}
          isMock={isMockAdapter}
          onSubmit={(e) => {
            e.preventDefault();
            updateMutation.mutate();
          }}
          isPending={updateMutation.isPending}
          submitLabel="Save Changes"
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
              <DialogTitle>Delete Device</DialogTitle>
              <DialogDescription>
                Are you sure you want to delete{" "}
                <strong>{deleteDevice?.name}</strong>? This action cannot be
                undone.
              </DialogDescription>
            </DialogHeader>
            <DialogFooter>
              <Button variant="outline" onClick={() => setDeleteDevice(null)}>
                Cancel
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
                Delete
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
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
  pulling,
  testing,
}: {
  device: Device;
  onPull: () => void;
  onTest: () => void;
  onEdit: () => void;
  onDelete: () => void;
  pulling: boolean;
  testing: boolean;
}) {
  const isOnline = device.status === "online";
  const isError = device.status === "error";

  const statusIcon = isOnline ? (
    <Wifi className="h-5 w-5 text-green-600 dark:text-green-400" />
  ) : isError ? (
    <AlertTriangle className="h-5 w-5 text-red-500" />
  ) : (
    <WifiOff className="h-5 w-5 text-gray-400" />
  );

  const statusBg = isOnline
    ? "bg-green-100 dark:bg-green-950"
    : isError
      ? "bg-red-100 dark:bg-red-950"
      : "bg-gray-100 dark:bg-gray-800";

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
                {ADAPTER_LABELS[device.adapter_type] ?? device.adapter_type}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-1">
            <StatusBadge status={device.status} />
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="h-7 w-7">
                  <MoreVertical className="h-4 w-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={onTest} disabled={testing}>
                  <Signal className="mr-2 h-4 w-4" /> Test Connection
                </DropdownMenuItem>
                <DropdownMenuItem onClick={onPull} disabled={pulling}>
                  <RefreshCw className="mr-2 h-4 w-4" /> Pull Records
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem onClick={onEdit}>
                  <Pencil className="mr-2 h-4 w-4" /> Edit
                </DropdownMenuItem>
                <DropdownMenuItem onClick={onDelete} className="text-red-600">
                  <Trash2 className="mr-2 h-4 w-4" /> Delete
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>

        <div className="mt-4 space-y-1.5 text-xs text-muted-foreground">
          {device.serial_number && (
            <p>
              S/N: <span className="font-mono">{device.serial_number}</span>
            </p>
          )}
          {device.branch?.name && <p>Branch: {device.branch.name}</p>}
          {device.attendance_records_count !== undefined && (
            <p>Records: {device.attendance_records_count.toLocaleString()}</p>
          )}
          <p>
            Sync:{" "}
            {device.auto_sync
              ? `Every ${device.sync_interval_minutes}m`
              : "Manual only"}
          </p>
          <p>
            Last sync:{" "}
            {device.last_sync_at
              ? new Date(device.last_sync_at).toLocaleString()
              : "Never"}
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
            Test
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
            Pull
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

function StatusBadge({ status }: { status: string }) {
  const styles: Record<string, string> = {
    online:
      "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300 border-0",
    offline:
      "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400 border-0",
    error: "bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300 border-0",
    pending:
      "bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300 border-0",
  };

  const dots: Record<string, string> = {
    online: "bg-green-500 animate-pulse",
    offline: "bg-gray-400",
    error: "bg-red-500",
    pending: "bg-amber-500",
  };

  return (
    <Badge variant="outline" className={styles[status] ?? ""}>
      <span
        className={`mr-1.5 inline-block h-1.5 w-1.5 rounded-full ${dots[status] ?? "bg-gray-400"}`}
      />
      {status}
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
            <Label>Device Name</Label>
            <Input
              value={form.name}
              onChange={(e) => set("name", e.target.value)}
              required
              placeholder="e.g. Main Entrance Terminal"
              className="mt-1"
            />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label>Adapter Type</Label>
              <Select
                value={form.adapter_type}
                onValueChange={(v) => set("adapter_type", v)}
              >
                <SelectTrigger className="mt-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="hikvision">Hikvision</SelectItem>
                  <SelectItem value="zkteco">ZKTeco</SelectItem>
                  <SelectItem value="suprema">Suprema</SelectItem>
                  <SelectItem value="mock">Mock (Simulator)</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label>Serial Number</Label>
              <Input
                value={form.serial_number}
                onChange={(e) => set("serial_number", e.target.value)}
                placeholder="Optional"
                className="mt-1"
              />
            </div>
          </div>

          {isMock && (
            <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300">
              Mock adapter simulates a device locally. No real hardware needed —
              it will generate test attendance events from your employees when
              you pull records.
            </div>
          )}

          {!isMock && (
            <div className="space-y-4 rounded-lg border p-4">
              <p className="text-xs font-medium text-muted-foreground uppercase">
                Connection Settings
              </p>
              <div className="grid grid-cols-3 gap-3">
                <div className="col-span-2">
                  <Label>IP Address</Label>
                  <Input
                    value={form.ip}
                    onChange={(e) => set("ip", e.target.value)}
                    required
                    placeholder="192.168.1.100"
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label>Port</Label>
                  <Input
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
                  <Label>Username</Label>
                  <Input
                    value={form.username}
                    onChange={(e) => set("username", e.target.value)}
                    placeholder="Optional"
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label>Password</Label>
                  <Input
                    type="password"
                    value={form.password}
                    onChange={(e) => set("password", e.target.value)}
                    placeholder="Optional"
                    className="mt-1"
                  />
                </div>
              </div>
              {form.adapter_type === "suprema" && (
                <div>
                  <Label>API Key</Label>
                  <Input
                    value={form.api_key}
                    onChange={(e) => set("api_key", e.target.value)}
                    placeholder="BioStar 2 API key"
                    className="mt-1"
                  />
                </div>
              )}
            </div>
          )}

          {branches.length > 0 && (
            <div>
              <Label>Branch</Label>
              <Select
                value={form.branch_public_id}
                onValueChange={(v) => set("branch_public_id", v)}
              >
                <SelectTrigger className="mt-1">
                  <SelectValue placeholder="Select branch" />
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
              Cancel
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
