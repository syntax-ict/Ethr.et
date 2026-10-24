"use client";

import { useState } from "react";
import { MapPin, Route } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { PaginationControls } from "@/components/shared/pagination-controls";
import { RoleGate } from "@/components/shared/role-gate";
import { useTeamVisits } from "@/features/attendance/api";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";

/**
 * The stops field staff logged, by day: who, when, where (with a map link)
 * and their note. A visit is evidence of work and never changes hours.
 */
export default function AttendanceVisitsPage() {
  const { t } = useT();
  const { formatDateTime } = useDateFormatters();
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [page, setPage] = useState(1);
  const visits = useTeamVisits({ date, page });
  const items = visits.data?.data ?? [];

  return (
    <RoleGate anyPermission={["viewAllAttendance"]}>
      <div className="space-y-4">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <p className="max-w-xl text-sm text-muted-foreground">
            {t(
              "visits.page_description",
              "Stops your field staff logged, with where and when. A visit is evidence of work and never changes hours.",
            )}
          </p>
          <div>
            <Label htmlFor="visits-date" className="text-xs">
              {t("visits.date", "Day")}
            </Label>
            <Input
              id="visits-date"
              type="date"
              className="mt-1 w-44"
              value={date}
              onChange={(e) => {
                setDate(e.target.value);
                setPage(1);
              }}
            />
          </div>
        </div>

        {visits.isError ? (
          <ErrorState onRetry={() => visits.refetch()} />
        ) : visits.isLoading ? (
          <Skeleton className="h-40" />
        ) : items.length === 0 ? (
          <EmptyState
            icon={Route}
            title={t("visits.empty", "No visits logged that day")}
          />
        ) : (
          <Card>
            <CardContent className="divide-y divide-border p-0">
              {items.map((v) => (
                <div
                  key={v.public_id}
                  className="flex flex-wrap items-start justify-between gap-3 p-4"
                >
                  <div className="min-w-0">
                    <p className="text-sm font-medium">{v.employee_name}</p>
                    <p className="text-xs text-muted-foreground">
                      {formatDateTime(v.occurred_at)}
                      {v.site_name ? ` · ${v.site_name}` : ""}
                    </p>
                    {v.note && <p className="mt-1 text-sm">{v.note}</p>}
                  </div>
                  <a
                    href={`https://www.openstreetmap.org/?mlat=${v.latitude}&mlon=${v.longitude}#map=17/${v.latitude}/${v.longitude}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1 text-xs text-primary hover:underline"
                  >
                    <MapPin className="h-3.5 w-3.5" aria-hidden="true" />
                    {t("visits.on_map", "On the map")}
                  </a>
                </div>
              ))}
            </CardContent>
          </Card>
        )}

        <PaginationControls meta={visits.data?.meta} onPageChange={setPage} />
      </div>
    </RoleGate>
  );
}
