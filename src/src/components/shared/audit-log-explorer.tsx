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
import { PageActions } from "@/components/shared/page-actions";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import {
  fetchAllAuditLogs,
  useAuditLogs,
  type AuditLogEndpoint,
} from "@/features/audit-log/api";
import { useDebounce } from "@/lib/hooks/useDebounce";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { statusTone } from "@/lib/utils/status-colors";
import { csvFromRows, saveCsv } from "@/lib/utils/csv-export";
import { toast } from "sonner";
import { PaginationControls } from "@/components/shared/pagination-controls";
import { auditActionLabel, auditEntityLabel } from "@/lib/audit/labels";

// Actions are not statuses, so they ask for a tone by name rather than
// borrowing a status that happens to be the right colour today: "updated"
// borrowed `draft`, and draft is now grey.
function getActionColor(action: string): string {
  const tone =
    action.includes("created") || action.includes("approved")
      ? "success"
      : action.includes("updated") || action.includes("processed")
        ? "info"
        : action.includes("deleted") || action.includes("rejected")
          ? "error"
          : "neutral";
  return `${statusTone(tone).badge} border-0`;
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
 *
 * The one addition is `showOrganisation`, for the platform view: its rows come
 * from every organisation, and a sign-in or a payroll approval says nothing
 * until it says whose.
 */
export function AuditLogExplorer({
  endpoint,
  queryKey,
  title,
  exportPrefix = "audit-log",
  showOrganisation = false,
}: {
  endpoint: AuditLogEndpoint;
  queryKey: string;
  title: string;
  exportPrefix?: string;
  showOrganisation?: boolean;
}) {
  const { t } = useT();
  const { formatDateTime } = useDateFormatters();
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState({ action: "", from: "", to: "" });
  const [exporting, setExporting] = useState(false);

  // The action box is free text; without the debounce every keystroke was a
  // request ("tenant" sent six), on a host whose firewall bans bursts and a
  // console route with its own rate limit (console QA F8).
  const debouncedAction = useDebounce(filters.action.trim(), 300);
  const query = useAuditLogs(endpoint, queryKey, {
    ...filters,
    action: debouncedAction,
    page,
  });

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
      t("audit_logs_page.csv.created_at", "Created At"),
      ...(showOrganisation
        ? [t("audit_logs_page.csv.organisation", "Organisation")]
        : []),
      t("audit_logs_page.csv.description", "Description"),
      t("audit_logs_page.csv.action", "Action"),
      t("audit_logs_page.csv.user", "User"),
      t("audit_logs_page.csv.user_id", "User ID"),
      t("audit_logs_page.csv.entity_type", "Entity Type"),
      t("audit_logs_page.csv.entity_id", "Entity ID"),
      t("audit_logs_page.csv.ip_address", "IP Address"),
    ];
    const rows = all.map((l) => [
      l.created_at,
      ...(showOrganisation ? [l.organisation?.name ?? ""] : []),
      auditActionLabel(l.action, t),
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
      <PageActions
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
                  mobileCards
                  caption={title}
                  headers={[
                    t("audit_logs_page.time"),
                    ...(showOrganisation
                      ? [t("audit_logs_page.organisation", "Organisation")]
                      : []),
                    t("audit_logs_page.action"),
                    t("audit_logs_page.entity"),
                    t("audit_logs_page.user"),
                    t("audit_logs_page.ip"),
                  ]}
                  colClassName={[
                    "",
                    ...(showOrganisation ? [""] : []),
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
                      ...(showOrganisation
                        ? [
                            <span
                              key="o"
                              className="text-xs text-foreground"
                              title={log.organisation?.public_id ?? undefined}
                            >
                              {log.organisation?.name ??
                                t("audit_logs_page.platform", "Platform")}
                            </span>,
                          ]
                        : []),
                      // The code under the words is what the action filter
                      // searches, so it stays visible, just not as the headline.
                      <div key="a" className="space-y-1">
                        <Badge
                          variant="outline"
                          className={`border-0 text-[11px] ${getActionColor(log.action)}`}
                        >
                          {auditActionLabel(log.action, t)}
                        </Badge>
                        <p className="font-mono text-xs text-muted-foreground">
                          {log.action}
                        </p>
                      </div>,
                      <span
                        key="e"
                        className="text-xs text-muted-foreground"
                        title={log.auditable_public_id ?? undefined}
                      >
                        {auditEntityLabel(log.auditable_type, t) ?? "—"}
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

            <PaginationControls meta={result.meta} onPageChange={setPage} />
          </>
        )}
      </QueryBoundary>
    </div>
  );
}
