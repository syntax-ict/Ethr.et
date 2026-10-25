"use client";

import { useState } from "react";
import { Smartphone } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { PaginationControls } from "@/components/shared/pagination-controls";
import { RoleGate } from "@/components/shared/role-gate";
import {
  useAttendanceDevices,
  useDecideDevice,
  type AttendanceDeviceInfo,
} from "@/features/attendance/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";

const FILTERS = ["pending", "active", "revoked"] as const;

/**
 * The phones people punch with (registered attendance devices): how many
 * people have one, phones waiting for approval, and revoking a lost one.
 */
export default function AttendanceDevicesPage() {
  const { t } = useT();
  const { formatDateTime } = useDateFormatters();
  const [status, setStatus] = useState<(typeof FILTERS)[number]>("pending");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const devices = useAttendanceDevices({
    status,
    search: search || undefined,
    page,
  });
  const decide = useDecideDevice();
  const items = devices.data?.data ?? [];
  const coverage = devices.data?.coverage;

  const filterLabel = {
    pending: t("devices.filter_pending", "Waiting"),
    active: t("devices.filter_active", "Registered"),
    revoked: t("devices.filter_revoked", "Revoked"),
  };

  function act(
    device: AttendanceDeviceInfo,
    action: "approve" | "reject" | "revoke",
  ) {
    decide.mutate(
      { publicId: device.public_id, action },
      {
        onSuccess: () =>
          toast.success(
            action === "approve"
              ? t("devices.approved", "Phone approved.")
              : action === "reject"
                ? t("devices.rejected", "Request rejected.")
                : t("devices.revoked", "Phone revoked."),
          ),
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t("devices.failed", "Could not change the phone."),
            ),
          ),
      },
    );
  }

  return (
    <RoleGate anyPermission={["manageAttendance"]}>
      <div className="space-y-4">
        <p className="max-w-2xl text-sm text-muted-foreground">
          {t(
            "devices.page_description",
            "The phone each person punches with. A phone registered away from work, or replacing another, waits here for approval. Revoke a phone that is lost or sold.",
          )}
        </p>

        {coverage && (
          <div className="grid gap-3 sm:grid-cols-3">
            <Stat
              label={t("devices.coverage_registered", "People with a phone")}
              value={`${coverage.registered} / ${coverage.employees}`}
            />
            <Stat
              label={t("devices.coverage_pending", "Waiting for approval")}
              value={String(coverage.pending)}
            />
            <Stat
              label={t("devices.coverage_missing", "Still to register")}
              value={String(
                Math.max(coverage.employees - coverage.registered, 0),
              )}
            />
          </div>
        )}

        <div className="flex flex-wrap items-center gap-2">
          {FILTERS.map((f) => (
            <Button
              key={f}
              size="sm"
              variant={status === f ? "default" : "outline"}
              onClick={() => {
                setStatus(f);
                setPage(1);
              }}
            >
              {filterLabel[f]}
            </Button>
          ))}
          <Input
            className="ml-auto w-full sm:w-64"
            placeholder={t("devices.search", "Search by name or code")}
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
          />
        </div>

        {devices.isError ? (
          <ErrorState onRetry={() => devices.refetch()} />
        ) : devices.isLoading ? (
          <Skeleton className="h-40" />
        ) : items.length === 0 ? (
          <EmptyState
            icon={Smartphone}
            title={t("devices.empty", "No phones here")}
          />
        ) : (
          <Card>
            <CardContent className="divide-y divide-border p-0">
              {items.map((d) => (
                <div
                  key={d.public_id}
                  className="flex flex-wrap items-center justify-between gap-3 p-4"
                >
                  <div className="min-w-0">
                    <p className="text-sm font-medium">
                      {d.employee?.name ??
                        t("devices.unknown_person", "Unknown")}
                      {d.employee?.employee_code
                        ? ` (${d.employee.employee_code})`
                        : ""}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {d.label ?? t("devices.unnamed", "Phone")}
                      {d.created_at ? `, ${formatDateTime(d.created_at)}` : ""}
                    </p>
                    {d.status === "pending" && !d.enrolled_inside_geofence && (
                      <Badge variant="outline" className="mt-1">
                        {t(
                          "devices.away_from_work",
                          "Registered away from work",
                        )}
                      </Badge>
                    )}
                  </div>
                  <div className="flex gap-2">
                    {d.status === "pending" ? (
                      <>
                        <Button
                          size="sm"
                          disabled={decide.isPending}
                          onClick={() => act(d, "approve")}
                        >
                          {t("devices.approve", "Approve")}
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={decide.isPending}
                          onClick={() => act(d, "reject")}
                        >
                          {t("devices.reject", "Reject")}
                        </Button>
                      </>
                    ) : d.status === "active" ? (
                      <Button
                        size="sm"
                        variant="outline"
                        disabled={decide.isPending}
                        onClick={() => act(d, "revoke")}
                      >
                        {t("devices.revoke", "Revoke")}
                      </Button>
                    ) : null}
                  </div>
                </div>
              ))}
            </CardContent>
          </Card>
        )}

        <PaginationControls meta={devices.data?.meta} onPageChange={setPage} />
      </div>
    </RoleGate>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <Card>
      <CardContent className="p-4">
        <p className="text-xs text-muted-foreground">{label}</p>
        <p className="text-xl font-semibold tabular-nums">{value}</p>
      </CardContent>
    </Card>
  );
}
