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
import {
  fetchAllAuditLogs,
  useAuditLogs,
  type AuditLogEndpoint,
} from "@/features/audit-log/api";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { statusBadgeClass } from "@/lib/utils/status-colors";
import { csvFromRows, saveCsv } from "@/lib/utils/csv-export";
import { toast } from "sonner";

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
  endpoint: AuditLogEndpoint;
  queryKey: string;
  title: string;
  description: string;
  exportPrefix?: string;
}) {
  const { t } = useT();
  const { formatDateTime } = useDateFormatters();
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState({ action: "", from: "", to: "" });
  const [exporting, setExporting] = useState(false);

  const query = useAuditLogs(endpoint, queryKey, { ...filters, page });

  const data = query.data;
  const logs = data?.data ?? [];

  /**
   * Every entry matching the filters, not the 50 on screen. The export used to
   * serialise `logs` — the current page — so a compliance export of a month's
   * trail held whichever 50 rows happened to be displayed.
   */
  async function exportCsv() {
    setExporting(true);
    const all = await fetchAllAuditLogs(endpoint, filters).catch(() => null);
    setExporting(false);
    if (all === null) {
      toast.error(t("employees.export_failed", "Export failed"));
      return;
    }
    if (!all.length) return;
    const headers = [
      "Created At",
      "Action",
      "User",
      "User ID",
      "Entity Type",
      "Entity ID",
      "IP Address",
    ];
    const rows = all.map((l) => [
      l.created_at,
      l.action,
      l.user?.name ?? "",
      l.user?.public_id ?? "",
      l.auditable_type ?? "",
      l.auditable_public_id ?? "",
      l.ip_address ?? "",
    ]);
    saveCsv(
      `${exportPrefix}-${new Date().toISOString().split("T")[0]}.csv`,
      csvFromRows(headers, rows),
    );
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
            disabled={logs.length === 0 || exporting}
          >
            <Download className="mr-2 h-4 w-4" />{" "}
            {t("audit_logs_page.export_csv")}
          </Button>
        }
      />

      <Card>
        <CardContent className="p-4">
          <div className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-4">
            <div className="sm:col-span-2">
              <Label htmlFor="audit_filter_action" className="text-xs">
                {t("audit_logs_page.action")}
              </Label>
              <div className="relative mt-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  id="audit_filter_action"
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
              <Label htmlFor="audit_filter_from" className="text-xs">
                {t("audit_logs_page.from")}
              </Label>
              <DualCalendarDateInput
                id="audit_filter_from"
                value={filters.from}
                onChange={(v) => {
                  setFilters((p) => ({ ...p, from: v }));
                  setPage(1);
                }}
                className="mt-1"
              />
            </div>
            <div>
              <Label htmlFor="audit_filter_to" className="text-xs">
                {t("audit_logs_page.to")}
              </Label>
              <DualCalendarDateInput
                id="audit_filter_to"
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
        {(result) => (
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
                      <span
                        key="e"
                        className="text-xs text-muted-foreground"
                        title={log.auditable_public_id ?? undefined}
                      >
                        {log.auditable_type
                          ? log.auditable_type.split("\\").pop()
                          : "—"}
                      </span>,
                      <span
                        key="u"
                        className="text-xs text-muted-foreground"
                        title={log.user?.public_id ?? undefined}
                      >
                        {log.user?.name ?? "—"}
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

            {result.meta && result.meta.last_page > 1 && (
              <div className="flex items-center justify-between">
                <p className="text-sm text-muted-foreground">
                  {t("audit_logs_page.showing")} {result.meta.from}–
                  {result.meta.to} {t("audit_logs_page.of")} {result.meta.total}
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
                    disabled={page >= result.meta.last_page}
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
