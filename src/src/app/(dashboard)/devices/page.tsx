"use client";

import { useEffect, useRef, useState } from "react";
import { branchesApi } from "@/features/organization/api";
import Link from "next/link";
import {
  Fingerprint,
  Moon,
  Plus,
  Wifi,
  WifiOff,
  RefreshCw,
  Loader2,
  Activity,
  Signal,
  AlertTriangle,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { PageActions } from "@/components/shared/page-actions";
import { EmptyState } from "@/components/shared/empty-state";
import { RoleGate } from "@/components/shared/role-gate";

import { DeviceActionsMenu } from "@/features/devices/components/device-actions-menu";
import { DeviceFormDialog } from "@/features/devices/components/device-form";
import { DeviceSetupWizard } from "@/features/devices/components/device-setup-wizard";
import { DeleteDeviceDialog } from "@/features/devices/components/delete-device-dialog";
import { IssuedAddressDialog } from "@/features/devices/components/issued-address-dialog";
import {
  issuedSetupFor,
  type IssuedSetup,
} from "@/features/devices/issued-setup";
import {
  DeviceEnrollmentsDialog,
  ImportHistoryDialog,
} from "@/features/devices/components/device-workforce-dialogs";
import {
  useCreateDevice,
  useDeleteDevice,
  useDevices,
  usePullDeviceEvents,
  useSyncAllDevices,
  useTestDeviceConnection,
  useUpdateDevice,
  type Device,
  type DeviceUpdate,
  isPulled,
} from "@/features/devices/api";
import type { DevicePayload } from "@/features/devices/payload";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { apiErrorMessage } from "@/lib/api/error-message";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { cn } from "@/lib/utils";
import { UnmatchedBadgesCard } from "@/features/devices/components/unmatched-badges-card";
import {
  deviceStatusBadgeClass,
  deviceStatusDotClass,
  deviceStatusLabelKey,
} from "@/features/devices/status";
import {
  DeviceHeardLine,
  syncErrorHintKeys,
} from "@/features/devices/components/device-heard";
import { FilterBar } from "@/components/shared/filter-bar";
import { useQueryFilters } from "@/lib/hooks/useQueryFilters";

const ADAPTER_LABELS: Record<string, string> = {
  hikvision: "Hikvision",
  zkteco: "ZKTeco",
  zkteco_adms: "ZKTeco (ADMS)",
  suprema: "Suprema",
  mock: "Mock (Simulator)",
};

export default function DevicesPage() {
  const { t } = useT();
  const [createOpen, setCreateOpen] = useState(false);
  // Add Device opens the guided setup; the full form is its Advanced link.
  const [wizardOpen, setWizardOpen] = useState(false);
  const [editDevice, setEditDevice] = useState<Device | null>(null);
  const [deleteDevice, setDeleteDevice] = useState<Device | null>(null);
  const [discoverDevice, setDiscoverDevice] = useState<Device | null>(null);
  const [historyDevice, setHistoryDevice] = useState<Device | null>(null);
  // In the address: "offline devices" is a link someone can send.
  const filters = useQueryFilters(
    { q: "", status: "all" },
    { status: ["all", "online", "quiet", "offline", "error", "pending"] },
  );
  const search = filters.values.q;
  const statusFilter = filters.values.status;

  const { data, isLoading } = useDevices({
    search: search || undefined,
    status: statusFilter === "all" ? undefined : statusFilter,
  });

  const { data: branches } = branchesApi.useList();

  const createMutation = useCreateDevice();
  // What to do on the new terminal, by how it connects. A push terminal's
  // address is shown once: the server keeps only a hash.
  const [issuedSetup, setIssuedSetup] = useState<IssuedSetup | null>(null);
  const updateMutation = useUpdateDevice();
  const deleteMutation = useDeleteDevice();
  const pullMutation = usePullDeviceEvents();
  const syncAllMutation = useSyncAllDevices();
  const testMutation = useTestDeviceConnection();

  function submitCreate(payload: DevicePayload) {
    createMutation.mutate(payload, {
      onSuccess: (created) => {
        toast.success(t("devices_page.registered"));
        setIssuedSetup(issuedSetupFor(created));
        setCreateOpen(false);
      },
      onError: (err: unknown) => {
        toast.error(apiErrorMessage(err, t("devices_page.add_failed")));
      },
    });
  }

  function submitUpdate(payload: DeviceUpdate) {
    if (!editDevice) return;
    updateMutation.mutate(
      {
        publicId: editDevice.public_id,
        payload,
      },
      {
        onSuccess: () => {
          toast.success(t("devices_page.updated"));
          setEditDevice(null);
        },
        onError: (err: unknown) =>
          toast.error(apiErrorMessage(err, t("devices_page.update_failed"))),
      },
    );
  }

  function confirmDelete(id: string) {
    deleteMutation.mutate(id, {
      onSuccess: () => {
        toast.success(t("devices_page.deleted"));
        setDeleteDevice(null);
      },
      onError: (err) =>
        toast.error(apiErrorMessage(err, t("devices_page.delete_failed"))),
    });
  }

  function pull(id: string) {
    pullMutation.mutate(id, {
      onSuccess: () => toast.success(t("devices_page.pull_initiated")),
      onError: (err) =>
        toast.error(apiErrorMessage(err, t("devices_page.pull_failed"))),
    });
  }

  // The page is open to hr_admin, but the seeded HR role holds only
  // device.viewAny and device.view; create, update (which also covers pull,
  // sync all and history import) and delete are tenant-admin abilities. Every
  // one of these buttons used to be shown and then refused with a 403 (N54).
  const { hasPermission } = usePermissions();
  const canCreate = hasPermission("device.create");
  const canUpdate = hasPermission("device.update");
  const canDelete = hasPermission("device.delete");

  // The health dashboard's Add device button arrives here as /devices#add.
  // Once, so closing the dialog does not bring it back on the next render.
  const openedFromHash = useRef(false);
  useEffect(() => {
    if (openedFromHash.current || !canCreate) return;
    if (window.location.hash !== "#add") return;
    const timer = window.setTimeout(() => {
      openedFromHash.current = true;
      setWizardOpen(true);
    }, 0);
    return () => window.clearTimeout(timer);
  }, [canCreate]);

  function syncAll() {
    syncAllMutation.mutate(undefined, {
      onSuccess: (d) =>
        toast.success(
          t(
            "devices_page.sync_dispatched_count",
            "Sync sent to :count devices",
            {
              count: d.dispatched,
            },
          ),
        ),
      onError: (err) =>
        toast.error(apiErrorMessage(err, t("devices_page.bulk_sync_failed"))),
    });
  }

  function testConnection(id: string, adapterType: string) {
    testMutation.mutate(id, {
      onSuccess: ({ status, error_code }) => {
        if (status === "online") {
          toast.success(t("devices_page.online_responding"));
        } else {
          // `status.pending` is the app-wide "Pending"; a device that is
          // pending is "not connected yet", which is its own label.
          const hint = syncErrorHintKeys(error_code, adapterType)
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

  const devices: Device[] = data?.data ?? [];

  return (
    <RoleGate anyPermission={["viewDevices"]}>
      <div className="space-y-6">
        <PageActions
          actions={
            <div className="flex items-center gap-2">
              <Button variant="outline" size="sm" asChild>
                <Link href="/devices/dashboard">
                  <Activity className="mr-2 h-4 w-4" />{" "}
                  {t("devices_page.health_dashboard")}
                </Link>
              </Button>
              {canUpdate && (
                <Button
                  variant="outline"
                  size="sm"
                  onClick={syncAll}
                  disabled={syncAllMutation.isPending || devices.length === 0}
                >
                  {syncAllMutation.isPending ? (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  ) : (
                    <RefreshCw className="mr-2 h-4 w-4" />
                  )}
                  {t("devices_page.sync_all")}
                </Button>
              )}
              {canCreate && (
                <Button onClick={() => setWizardOpen(true)}>
                  <Plus className="mr-2 h-4 w-4" />{" "}
                  {t("devices_page.add_device")}
                </Button>
              )}
            </div>
          }
        />

        <UnmatchedBadgesCard />

        <FilterBar activeCount={filters.activeCount} onClear={filters.clear}>
          <Input
            placeholder={t("devices_page.search_placeholder")}
            aria-label={t("devices_page.search_placeholder")}
            value={search}
            onChange={(e) => filters.set({ q: e.target.value })}
            className="max-w-xs"
          />
          <Select
            value={statusFilter}
            onValueChange={(v) => filters.set({ status: v })}
          >
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
              <SelectItem value="quiet">{t("devices_page.quiet")}</SelectItem>
              <SelectItem value="offline">
                {t("devices_page.offline")}
              </SelectItem>
              <SelectItem value="error">{t("devices_page.error")}</SelectItem>
              <SelectItem value="pending">
                {t("devices_page.pending")}
              </SelectItem>
            </SelectContent>
          </Select>
        </FilterBar>

        {isLoading ? (
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
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
              canCreate ? (
                <Button onClick={() => setWizardOpen(true)}>
                  <Plus className="mr-2 h-4 w-4" />{" "}
                  {t("devices_page.add_device")}
                </Button>
              ) : undefined
            }
          />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {devices.map((d) => (
              <DeviceCard
                key={d.public_id}
                device={d}
                onPull={canUpdate ? () => pull(d.public_id) : undefined}
                onTest={() => testConnection(d.public_id, d.adapter_type)}
                onEdit={canUpdate ? () => setEditDevice(d) : undefined}
                onDelete={canDelete ? () => setDeleteDevice(d) : undefined}
                onDiscover={() => setDiscoverDevice(d)}
                onImportHistory={
                  canUpdate ? () => setHistoryDevice(d) : undefined
                }
                pulling={pullMutation.isPending}
                testing={testMutation.isPending}
              />
            ))}
          </div>
        )}

        <DeviceSetupWizard
          open={wizardOpen}
          onOpenChange={setWizardOpen}
          branches={branches?.data ?? []}
          onAdvanced={() => {
            setWizardOpen(false);
            setCreateOpen(true);
          }}
          onDiscover={setDiscoverDevice}
        />

        {/* Register: the full form, reached from the wizard's Advanced link */}
        <DeviceFormDialog
          open={createOpen}
          onOpenChange={setCreateOpen}
          title={t("devices_page.register_device")}
          mode="create"
          branches={branches?.data ?? []}
          onSubmit={submitCreate}
          isPending={createMutation.isPending}
          submitLabel={t("devices_page.register")}
        />

        <IssuedAddressDialog
          setup={issuedSetup}
          onClose={() => setIssuedSetup(null)}
        />

        {/* Edit: the same form as Register, and as the device's own page */}
        {editDevice && (
          <DeviceFormDialog
            key={editDevice.public_id}
            open
            onOpenChange={(open) => {
              if (!open) setEditDevice(null);
            }}
            title={t("devices_page.edit_device")}
            mode="edit"
            device={editDevice}
            branches={branches?.data ?? []}
            onSubmit={submitUpdate}
            isPending={updateMutation.isPending}
            submitLabel={t("leave_types_page.save_changes")}
          />
        )}

        <DeleteDeviceDialog
          deviceName={deleteDevice?.name ?? null}
          pending={deleteMutation.isPending}
          onConfirm={() =>
            deleteDevice && confirmDelete(deleteDevice.public_id)
          }
          onClose={() => setDeleteDevice(null)}
        />

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
  /** Absent when the user may not do it: the item is then not offered. */
  onPull?: () => void;
  onTest: () => void;
  onEdit?: () => void;
  onDelete?: () => void;
  onDiscover: () => void;
  onImportHistory?: () => void;
  pulling: boolean;
  testing: boolean;
}) {
  const { t } = useT();
  const { formatDateTime } = useDateFormatters();
  const isOnline = device.status === "online";
  const isError = device.status === "error";
  const isQuiet = device.status === "quiet";
  // A terminal that only sends is never called, so it cannot be tested, pulled
  // from, asked for its users or asked for history: the card says what ETHR
  // last heard from it instead.
  const sendsOnly = device.receives_only === true;
  const pulled = isPulled(device);

  const statusIcon = isOnline ? (
    <Wifi className="h-5 w-5 text-success" />
  ) : isError ? (
    <AlertTriangle className="h-5 w-5 text-destructive" />
  ) : isQuiet ? (
    <Moon className="h-5 w-5 text-info" />
  ) : (
    <WifiOff className="h-5 w-5 text-muted-foreground" />
  );

  const statusBg = isOnline
    ? "bg-success-soft"
    : isError
      ? "bg-destructive-soft"
      : isQuiet
        ? "bg-info-soft"
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
            <DeviceActionsMenu
              device={device}
              onTest={onTest}
              onPull={onPull}
              testing={testing}
              pulling={pulling}
              onEdit={onEdit}
              onDelete={onDelete}
              onDiscover={onDiscover}
              onImportHistory={onImportHistory}
            />
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
          {!pulled ? (
            <DeviceHeardLine device={device} showSetupLink />
          ) : (
            <>
              <p>
                {t("devices_page.sync")}:{" "}
                {device.auto_sync
                  ? t(
                      "devices_page.every_minutes",
                      "At most every :count min",
                      {
                        count: device.sync_interval_minutes,
                      },
                    )
                  : t("devices_page.manual_only")}
              </p>
              <p>
                {t("attendance.kiosks_page.last_active")}:{" "}
                {device.last_sync_at
                  ? formatDateTime(device.last_sync_at)
                  : t("devices_page.never")}
              </p>
            </>
          )}
        </div>

        {!sendsOnly && (
          <div
            className={cn(
              "mt-3 grid gap-2",
              onPull ? "grid-cols-2" : "grid-cols-1",
            )}
          >
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
            {onPull && pulled && (
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
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function StatusBadge({ status }: { status: string }) {
  const { t } = useT();
  const labelKey = deviceStatusLabelKey(status);

  return (
    <Badge variant="outline" className={deviceStatusBadgeClass(status)}>
      <span
        className={`mr-1.5 inline-block h-1.5 w-1.5 rounded-full ${deviceStatusDotClass(status)}`}
      />
      {labelKey ? t(labelKey) : status}
    </Badge>
  );
}
