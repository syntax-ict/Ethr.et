"use client";

import Link from "next/link";
import {
  Fingerprint,
  Wifi,
  WifiOff,
  AlertTriangle,
  Clock,
  Activity,
  ArrowLeft,
  Plus,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/components/shared/page-header";
import { SimpleTable } from "@/components/shared/simple-table";
import { RoleGate } from "@/components/shared/role-gate";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";

interface DashboardResponse {
  total: number;
  online: number;
  offline: number;
  error: number;
  pending: number;
  auto_sync_enabled: number;
  events_today: number;
  last_sync_at: string | null;
  sync_stats_24h: {
    success: number;
    partial: number;
    failed: number;
    offline: number;
  };
}

interface Device {
  public_id: string;
  name: string;
  adapter_type: string;
  serial_number: string | null;
  status: string;
  last_sync_at: string | null;
  branch?: { name: string } | null;
  attendance_records_count?: number;
}

export default function DeviceDashboardPage() {
  const { t } = useT();
  const { data: stats, isLoading: statsLoading } = useQuery<DashboardResponse>({
    queryKey: ["devices", "dashboard"],
    queryFn: async () => (await apiClient.get("/devices/dashboard")).data,
    refetchInterval: 30000,
  });

  const { data: devices, isLoading: devicesLoading } = useQuery({
    queryKey: ["devices"],
    queryFn: async () => (await apiClient.get("/devices")).data,
    refetchInterval: 30000,
  });

  const allDevices: Device[] = devices?.data ?? [];

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <Button variant="ghost" size="sm" asChild>
            <Link href="/devices">
              <ArrowLeft className="mr-2 h-4 w-4" />{" "}
              {t("devices_dashboard_page.all_devices")}
            </Link>
          </Button>
          <Button size="sm" asChild>
            <Link href="/devices">
              <Plus className="mr-2 h-4 w-4" /> {t("devices_page.add_device")}
            </Link>
          </Button>
        </div>

        <PageHeader
          title={t("devices_dashboard_page.title")}
          description={t("devices_dashboard_page.description")}
        />

        {statsLoading || !stats ? (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            {Array.from({ length: 5 }).map((_, i) => (
              <Skeleton key={i} className="h-24" />
            ))}
          </div>
        ) : (
          <>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
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
              <StatusCard
                icon={Activity}
                title={t("devices_dashboard_page.events_today")}
                value={stats.events_today}
                color="brand"
              />
            </div>

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
                {stats.last_sync_at && (
                  <p className="mt-3 text-xs text-muted-foreground">
                    {t("devices_dashboard_page.last_sync_across")}:{" "}
                    {timeAgo(stats.last_sync_at, t)}
                  </p>
                )}
              </CardContent>
            </Card>
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
            ) : allDevices.length === 0 ? (
              <p className="px-4 py-12 text-center text-sm text-muted-foreground">
                {t("devices_dashboard_page.no_devices_yet")}
              </p>
            ) : (
              <SimpleTable
                caption={t("devices_page.title", "Devices")}
                headers={[
                  t("devices_page.device_name"),
                  t("devices_dashboard_page.adapter"),
                  t("devices_dashboard_page.serial"),
                  t("attendance.kiosks_page.branch").replace(" *", ""),
                  t("common.status"),
                  t("devices_page.records"),
                  t("devices_dashboard_page.last_sync"),
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
                    <span key="n" className="font-medium">
                      {d.name}
                    </span>,
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
                      className={statusClass(d.status)}
                    >
                      <span
                        className={`mr-1.5 inline-block h-1.5 w-1.5 rounded-full ${dotClass(d.status)}`}
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
                          {timeAgo(d.last_sync_at, t)}
                        </span>
                      ) : (
                        t("devices_page.never")
                      )}
                    </span>,
                  ],
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

const colorClass: Record<string, string> = {
  info: "bg-info-soft text-info-on-soft",
  success: "bg-success-soft text-success-on-soft",
  neutral: "bg-neutral-soft text-neutral-on-soft",
  danger: "bg-destructive-soft text-destructive-on-soft",
  brand: "bg-brand-soft text-brand-on-soft",
};

function StatusCard({
  icon: Icon,
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
    <Card>
      <CardContent className="flex items-center gap-3 p-4">
        <div
          className={`flex h-10 w-10 items-center justify-center rounded-xl ${colorClass[color]}`}
        >
          <Icon className="h-5 w-5" />
        </div>
        <div>
          <p className="text-xs text-muted-foreground">{title}</p>
          <p className="text-2xl font-bold text-foreground">{value}</p>
        </div>
      </CardContent>
    </Card>
  );
}

function statusClass(status: string): string {
  const map: Record<string, string> = {
    online: "border-0 bg-success-soft text-success-on-soft",
    offline: "border-0 bg-neutral-soft text-neutral-on-soft",
    error: "border-0 bg-destructive-soft text-destructive-on-soft",
    pending: "border-0 bg-warning-soft text-warning-on-soft",
  };
  return map[status] ?? "";
}

function dotClass(status: string): string {
  const map: Record<string, string> = {
    online: "bg-success animate-pulse",
    offline: "bg-muted-foreground",
    error: "bg-destructive",
    pending: "bg-warning",
  };
  return map[status] ?? "bg-muted-foreground";
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
