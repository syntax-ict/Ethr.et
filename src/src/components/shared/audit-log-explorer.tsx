"use client";

import { useState } from "react";
import { Activity, Search, Download } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { Badge } from "@/components/ui/badge";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { statusBadgeClass } from "@/lib/utils/status-colors";

export interface AuditLogEntry {
  action: string;
  auditable_type?: string;
  auditable_id?: number;
  user_id?: number;
  data?: Record<string, unknown>;
  ip_address?: string;
  user_agent?: string;
  created_at: string;
}

function getActionColor(action: string): string {
  if (action.includes("created") || action.includes("approved"))
    return statusBadgeClass("created");
  if (action.includes("updated") || action.includes("processed"))
    return statusBadgeClass("draft");
  if (action.includes("deleted") || action.includes("rejected"))
    return statusBadgeClass("rejected");
  return statusBadgeClass("unknown");
}

/**
 * Filterable, paginated audit log view.
 *
 * Two endpoints serve the same shape at different scopes — `/audit-logs` shows
 * one tenant their own trail, `/admin/audit` shows a super admin every tenant's
 * — and the only differences are the URL and who is allowed through the gate.
 * Callers supply both; everything the reader sees is identical, which is the
 * point: a compliance view that behaves differently depending on which door you
 * came through is a view nobody can be trained on.
 */
export function AuditLogExplorer({
  endpoint,
  queryKey,
  title,
  description,
  exportPrefix = "audit-log",
}: {
  endpoint: string;
  queryKey: string;
  title: string;
  description: string;
  exportPrefix?: string;
}) {
  const { t } = useT();
  const { formatDateTime } = useDateFormatters();
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState({ action: "", from: "", to: "" });

  const query = useQuery({
    queryKey: [queryKey, page, filters],
    queryFn: async () => {
      const params: Record<string, unknown> = { page, per_page: 50 };
      if (filters.action) params["filter[action]"] = filters.action;
      if (filters.from) params["filter[from]"] = filters.from;
      if (filters.to) params["filter[to]"] = filters.to;
      const { data } = await apiClient.get(endpoint, { params });
      return data;
    },
  });

  const data = query.data;
  const logs: AuditLogEntry[] = data?.data ?? [];

  function exportCsv() {
    if (!logs.length) return;
    const headers = [
      "Created At",
      "Action",
      "User ID",
      "Entity Type",
      "Entity ID",
      "IP Address",
    ];
    const rows = logs.map((l) => [
      l.created_at,
      l.action,
      l.user_id ?? "",
      l.auditable_type ?? "",
      l.auditable_id ?? "",
      l.ip_address ?? "",
    ]);
    const csv = [
      headers.join(","),
      ...rows.map((r: unknown[]) =>
        r.map((v) => `"${String(v).replace(/"/g, '""')}"`).join(","),
      ),
    ].join("\n");
    const blob = new Blob([csv], { type: "text/csv" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `${exportPrefix}-${new Date().toISOString().split("T")[0]}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={title}
        description={description}
        actions={
          <Button
            variant="outline"
            size="sm"
            onClick={exportCsv}
            disabled={logs.length === 0}
          >
            <Download className="mr-2 h-4 w-4" />{" "}
            {t("audit_logs_page.export_csv")}
          </Button>
        }
      />

      <Card>
        <CardContent className="p-4">
          <div className="grid gap-3 sm:grid-cols-4">
            <div className="sm:col-span-2">
              <Label className="text-xs">{t("audit_logs_page.action")}</Label>
              <div className="relative mt-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  value={filters.action}
                  onChange={(e) => {
                    setFilters((p) => ({ ...p, action: e.target.value }));
                    setPage(1);
                  }}
                  placeholder={t("audit_logs_page.action_placeholder")}
                  className="pl-9"
                />
              </div>
            </div>
            <div>
              <Label className="text-xs">{t("audit_logs_page.from")}</Label>
              <DualCalendarDateInput
                value={filters.from}
                onChange={(v) => {
                  setFilters((p) => ({ ...p, from: v }));
                  setPage(1);
                }}
                className="mt-1"
              />
            </div>
            <div>
              <Label className="text-xs">{t("audit_logs_page.to")}</Label>
              <DualCalendarDateInput
                value={filters.to}
                onChange={(v) => {
                  setFilters((p) => ({ ...p, to: v }));
                  setPage(1);
                }}
                className="mt-1"
              />
            </div>
          </div>
        </CardContent>
      </Card>

      <QueryBoundary
        query={query}
        loading={
          <div className="space-y-2">
            {Array.from({ length: 8 }).map((_, i) => (
              <Skeleton key={i} className="h-14 w-full" />
            ))}
          </div>
        }
        isEmpty={() => logs.length === 0}
        empty={
          <EmptyState
            icon={Activity}
            title={t("audit_logs_page.no_logs")}
            description={t("audit_logs_page.no_logs_desc")}
          />
        }
      >
        {() => (
          <>
            <Card>
              <CardContent className="p-0">
                <SimpleTable
                  caption={title}
                  headers={[
                    t("audit_logs_page.time"),
                    t("audit_logs_page.action"),
                    t("audit_logs_page.entity"),
                    t("audit_logs_page.user"),
                    t("audit_logs_page.ip"),
                  ]}
                  colClassName={[
                    "",
                    "",
                    "hidden md:table-cell",
                    "hidden lg:table-cell",
                    "hidden lg:table-cell",
                  ]}
                  rows={logs.map((log, index) => ({
                    key: `${log.created_at}-${log.action}-${index}`,
                    cells: [
                      <span
                        key="t"
                        className="whitespace-nowrap text-xs text-muted-foreground"
                      >
                        {formatDateTime(log.created_at)}
                      </span>,
                      <Badge
                        key="a"
                        variant="outline"
                        className={`border-0 font-mono text-[11px] ${getActionColor(log.action)}`}
                      >
                        {log.action}
                      </Badge>,
                      <span key="e" className="text-xs text-muted-foreground">
                        {log.auditable_type
                          ? `${log.auditable_type.split("\\").pop()}#${log.auditable_id}`
                          : "—"}
                      </span>,
                      <span key="u" className="text-xs text-muted-foreground">
                        {log.user_id
                          ? `${t("audit_logs_page.user_hash")}${log.user_id}`
                          : "—"}
                      </span>,
                      <span
                        key="ip"
                        className="font-mono text-xs text-muted-foreground"
                      >
                        {log.ip_address ?? "—"}
                      </span>,
                    ],
                  }))}
                />
              </CardContent>
            </Card>

            {data.meta && data.meta.last_page > 1 && (
              <div className="flex items-center justify-between">
                <p className="text-sm text-muted-foreground">
                  {t("audit_logs_page.showing")} {data.meta.from}–{data.meta.to}{" "}
                  {t("audit_logs_page.of")} {data.meta.total}
                </p>
                <div className="flex gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page <= 1}
                    onClick={() => setPage(page - 1)}
                  >
                    {t("audit_logs_page.previous")}
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page >= data.meta.last_page}
                    onClick={() => setPage(page + 1)}
                  >
                    {t("audit_logs_page.next")}
                  </Button>
                </div>
              </div>
            )}
          </>
        )}
      </QueryBoundary>
    </div>
  );
}
