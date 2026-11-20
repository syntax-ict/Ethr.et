"use client";

import { StatCard, type StatTone } from "@/components/dashboard/stat-card";
import Link from "next/link";
import {
  Fingerprint,
  Wifi,
  WifiOff,
  AlertTriangle,
  Clock,
  Hourglass,
  Moon,
  ArrowLeft,
  Plus,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  deviceStatusBadgeClass,
  deviceStatusDotClass,
  deviceStatusLabelKey,
} from "@/features/devices/status";
import { DeviceActionsMenu } from "@/features/devices/components/device-actions-menu";
import { SimpleTable } from "@/components/shared/simple-table";
import { RoleGate } from "@/components/shared/role-gate";
import {
  useDeviceDashboard,
  useDevices,
  type Device,
} from "@/features/devices/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { ErrorState } from "@/components/shared/error-state";

export default function DeviceDashboardPage() {
  const { t } = useT();
  const { timeAgo } = useDateFormatters();
  const { hasPermission } = usePermissions();
  const canCreate = hasPermission("device.create");
  const {
    data: stats,
    isLoading: statsLoading,
    isError: statsError,
    refetch: refetchStats,
  } = useDeviceDashboard({
    refetchInterval: 30000,
  });

  const {
    data: devices,
    isLoading: devicesLoading,
    isError: devicesError,
    refetch: refetchDevices,
  } = useDevices(undefined, {
    refetchInterval: 30000,
  });

  const allDevices: Device[] = devices?.data ?? [];

  // Sync logs are written only when ETHR pulls from a device, so for a
  // terminal that only sends the card would be four zeros that mean nothing.
  const anyPulled = allDevices.some((d) => d.receives_only !== true);

  return (
    <RoleGate anyPermission={["viewDevices"]}>
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <Button variant="ghost" size="sm" asChild>
            <Link href="/devices">
              <ArrowLeft className="mr-2 h-4 w-4" />{" "}
              {t("devices_dashboard_page.all_devices")}
            </Link>
          </Button>
          {canCreate && (
            <Button size="sm" asChild>
              <Link href="/devices#add">
                <Plus className="mr-2 h-4 w-4" /> {t("devices_page.add_device")}
              </Link>
            </Button>
          )}
        </div>

        {stats && stats.quarantined.punches > 0 && (
          <div
            role="status"
            className="space-y-1 rounded-md border border-warning-edge bg-warning-soft p-3 text-sm text-warning-on-soft"
          >
            <p className="font-medium">
              {t("devices_dashboard_page.quarantine_title")}
            </p>
            <p>
              {stats.quarantined.punches === 1
                ? t("devices_dashboard_page.quarantine_body_one")
                : t("devices_dashboard_page.quarantine_body_many", undefined, {
                    punches: stats.quarantined.punches.toLocaleString(),
                  })}
            </p>
            <ul className="space-y-0.5">
              {stats.quarantined.devices.map((entry) => (
                <li key={entry.device_public_id}>
                  <Link
                    href={`/devices/${entry.device_public_id}#quarantine`}
                    className="font-medium underline underline-offset-2"
                  >
                    {entry.punches === 1
                      ? t(
                          "devices_dashboard_page.quarantine_device_one",
                          undefined,
                          {
                            device: entry.name,
                          },
                        )
                      : t(
                          "devices_dashboard_page.quarantine_device_many",
                          undefined,
                          {
                            device: entry.name,
                            punches: entry.punches.toLocaleString(),
                          },
                        )}
                  </Link>
                </li>
              ))}
            </ul>
          </div>
        )}

        {stats && stats.unmatched.badges > 0 && (
          <div
            role="status"
            className="space-y-1 rounded-md border border-warning-edge bg-warning-soft p-3 text-sm text-warning-on-soft"
          >
            <p className="font-medium">
              {t("devices_dashboard_page.attention_title")}
            </p>
            <p>
              {t("devices_dashboard_page.attention_body", undefined, {
                badges: stats.unmatched.badges.toLocaleString(),
                punches: stats.unmatched.punches.toLocaleString(),
              })}
            </p>
            <Link
              href="/devices#unmatched"
              className="inline-block font-medium underline underline-offset-2"
            >
              {t("devices_dashboard_page.review_badges")}
            </Link>
          </div>
        )}

        {statsError ? (
          <ErrorState compact onRetry={() => refetchStats()} />
        ) : statsLoading || !stats ? (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
            {Array.from({ length: 6 }).map((_, i) => (
              <Skeleton key={i} className="h-24" />
            ))}
          </div>
        ) : (
          <>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
              <StatusCard
                icon={Fingerprint}
                title={t("devices_dashboard_page.total_devices")}
                value={stats.total}
                color="info"
              />
              <StatusCard
                icon={Wifi}
                title={t("devices_page.online")}
                value={stats.online}
                color="success"
              />
              <StatusCard
                icon={Moon}
                title={t("devices_page.quiet")}
                value={stats.quiet}
                color="info"
              />
              <StatusCard
                icon={Hourglass}
                title={t("devices_page.pending")}
                value={stats.pending}
                color="warning"
              />
              <StatusCard
                icon={WifiOff}
                title={t("devices_page.offline")}
                value={stats.offline}
                color="neutral"
              />
              <StatusCard
                icon={AlertTriangle}
                title={t("devices_page.error")}
                value={stats.error}
                color="danger"
              />
            </div>

            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="text-sm">
                  {t("devices_dashboard_page.punches_today")}
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                  <div>
                    <p className="text-xs text-muted-foreground">
                      {t("devices_dashboard_page.punches_recorded")}
                    </p>
                    <p className="text-xl font-bold text-foreground">
                      {stats.punches_today.recorded.toLocaleString()}
                    </p>
                  </div>
                  <div>
                    <p className="text-xs text-muted-foreground">
                      {t("devices_dashboard_page.punches_held")}
                    </p>
                    <p
                      className={`text-xl font-bold ${stats.punches_today.held > 0 ? "text-warning" : "text-foreground"}`}
                    >
                      {stats.punches_today.held.toLocaleString()}
                    </p>
                  </div>
                  <div>
                    <p className="text-xs text-muted-foreground">
                      {t("devices_dashboard_page.punches_waiting")}
                    </p>
                    <p className="text-xl font-bold text-foreground">
                      {stats.punches_today.waiting.toLocaleString()}
                    </p>
                  </div>
                </div>
                {stats.last_sync_at && (
                  <p className="mt-3 text-xs text-muted-foreground">
                    {t("devices_dashboard_page.last_heard", undefined, {
                      time: timeAgo(stats.last_sync_at),
                    })}
                  </p>
                )}
              </CardContent>
            </Card>

            {anyPulled && (
              <Card>
                <CardHeader className="pb-3">
                  <CardTitle className="text-sm">
                    {t("devices_dashboard_page.sync_overview")}
                  </CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                      <p className="text-xs text-muted-foreground">
                        {t("devices_dashboard_page.successful")}
                      </p>
                      <p className="text-xl font-bold text-success">
                        {stats.sync_stats_24h.success}
                      </p>
                    </div>
                    <div>
                      <p className="text-xs text-muted-foreground">
                        {t("devices_dashboard_page.partial")}
                      </p>
                      <p className="text-xl font-bold text-warning">
                        {stats.sync_stats_24h.partial}
                      </p>
                    </div>
                    <div>
                      <p className="text-xs text-muted-foreground">
                        {t("devices_dashboard_page.failed")}
                      </p>
                      <p className="text-xl font-bold text-destructive">
                        {stats.sync_stats_24h.failed}
                      </p>
                    </div>
                    <div>
                      <p className="text-xs text-muted-foreground">
                        {t("devices_dashboard_page.auto_sync_enabled")}
                      </p>
                      <p className="text-xl font-bold text-foreground">
                        {stats.auto_sync_enabled}
                      </p>
                    </div>
                  </div>
                </CardContent>
              </Card>
            )}
          </>
        )}

        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t("devices_dashboard_page.device_status")}
            </CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            {devicesLoading ? (
              <div className="space-y-2 p-4">
                {Array.from({ length: 3 }).map((_, i) => (
                  <Skeleton key={i} className="h-14" />
                ))}
              </div>
            ) : devicesError ? (
              <ErrorState onRetry={() => refetchDevices()} />
            ) : allDevices.length === 0 ? (
              <p className="px-4 py-12 text-center text-sm text-muted-foreground">
                {t("devices_dashboard_page.no_devices_yet")}
              </p>
            ) : (
              <SimpleTable
                mobileCards
                caption={t("devices_page.title", "Devices")}
                headers={[
                  t("devices_page.device_name"),
                  t("devices_dashboard_page.make"),
                  t("devices_dashboard_page.serial"),
                  t("attendance.kiosks_page.branch").replace(" *", ""),
                  t("common.status"),
                  t("devices_page.records"),
                  t("devices_dashboard_page.last_contact"),
                ]}
                align={[
                  "left",
                  "left",
                  "left",
                  "left",
                  "left",
                  "right",
                  "left",
                ]}
                colClassName={[
                  "",
                  "hidden sm:table-cell",
                  "hidden md:table-cell",
                  "hidden md:table-cell",
                  "",
                  "hidden lg:table-cell",
                  "hidden lg:table-cell",
                ]}
                rows={allDevices.map((d) => ({
                  key: d.public_id,
                  cells: [
                    <Link
                      key="n"
                      href={`/devices/${d.public_id}`}
                      className="font-medium hover:underline"
                    >
                      {d.name}
                    </Link>,
                    <span key="a" className="capitalize text-muted-foreground">
                      {d.adapter_type === "mock"
                        ? t("devices_page.mock_simulator")
                        : (ADAPTER_LABELS[d.adapter_type] ?? d.adapter_type)}
                    </span>,
                    <span key="sn" className="font-mono text-muted-foreground">
                      {d.serial_number ?? "—"}
                    </span>,
                    <span key="b" className="text-muted-foreground">
                      {d.branch?.name ?? "—"}
                    </span>,
                    <Badge
                      key="s"
                      variant="outline"
                      className={deviceStatusBadgeClass(d.status)}
                    >
                      <span
                        className={`mr-1.5 inline-block h-1.5 w-1.5 rounded-full ${deviceStatusDotClass(d.status)}`}
                      />
                      {statusLabel(d.status, t)}
                    </Badge>,
                    <span
                      key="r"
                      className="tabular-nums text-muted-foreground"
                    >
                      {d.attendance_records_count?.toLocaleString() ?? "—"}
                    </span>,
                    <span key="ls" className="text-xs text-muted-foreground">
                      {d.last_sync_at ? (
                        <span className="flex items-center gap-1">
                          <Clock className="h-3 w-3" />{" "}
                          {timeAgo(d.last_sync_at)}
                        </span>
                      ) : (
                        t("devices_page.never")
                      )}
                    </span>,
                  ],
                  actions: (
                    <div className="flex justify-end">
                      <DeviceActionsMenu device={d} />
                    </div>
                  ),
                }))}
              />
            )}
          </CardContent>
        </Card>
      </div>
    </RoleGate>
  );
}

const ADAPTER_LABELS: Record<string, string> = {
  hikvision: "Hikvision",
  zkteco: "ZKTeco",
  suprema: "Suprema",
  mock: "Mock",
};

/** The local colour names, as `StatCard` tones. */
const TONE: Record<string, StatTone> = {
  info: "info",
  success: "success",
  warning: "warning",
  neutral: "neutral",
  danger: "error",
  brand: "brand",
};

function StatusCard({
  icon,
  title,
  value,
  color,
}: {
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  value: number;
  color: string;
}) {
  return (
    <StatCard
      icon={icon}
      tone={TONE[color] ?? "primary"}
      label={title}
      value={value}
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
