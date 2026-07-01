"use client";

import { useState } from "react";
import { Activity, Search, Download, Filter } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { RoleGate } from "@/components/shared/role-gate";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

interface AuditLog {
  id: number;
  action: string;
  auditable_type?: string;
  auditable_id?: number;
  user_id?: number;
  data?: Record<string, unknown>;
  ip_address?: string;
  user_agent?: string;
  created_at: string;
}

const actionColors: Record<string, string> = {
  created: "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300",
  updated: "bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300",
  deleted: "bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300",
  approved: "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300",
  rejected: "bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300",
  processed:
    "bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300",
};

function getActionColor(action: string): string {
  for (const [key, color] of Object.entries(actionColors)) {
    if (action.includes(key)) return color;
  }
  return "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300";
}

export default function AuditLogsPage() {
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState({ action: "", from: "", to: "" });

  const { data, isLoading } = useQuery({
    queryKey: ["audit-logs", page, filters],
    queryFn: async () => {
      const params: Record<string, unknown> = { page, per_page: 50 };
      if (filters.action) params["filter[action]"] = filters.action;
      if (filters.from) params["filter[from]"] = filters.from;
      if (filters.to) params["filter[to]"] = filters.to;
      const { data } = await apiClient.get("/audit-logs", { params });
      return data;
    },
  });

  function exportCsv() {
    if (!data?.data?.length) return;
    const headers = [
      "Created At",
      "Action",
      "User ID",
      "Entity Type",
      "Entity ID",
      "IP Address",
    ];
    const rows = data.data.map((l: AuditLog) => [
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
    link.download = `audit-log-${new Date().toISOString().split("T")[0]}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  }

  const logs: AuditLog[] = data?.data ?? [];

  return (
    <RoleGate minRole="tenant_admin">
      <div className="space-y-6">
        <PageHeader
          title="Audit Log"
          description="Track all sensitive operations across your organization"
          actions={
            <Button
              variant="outline"
              size="sm"
              onClick={exportCsv}
              disabled={logs.length === 0}
            >
              <Download className="mr-2 h-4 w-4" /> Export CSV
            </Button>
          }
        />

        <Card>
          <CardContent className="p-4">
            <div className="grid gap-3 sm:grid-cols-4">
              <div className="sm:col-span-2">
                <Label className="text-xs">Action</Label>
                <div className="relative mt-1">
                  <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    value={filters.action}
                    onChange={(e) => {
                      setFilters((p) => ({ ...p, action: e.target.value }));
                      setPage(1);
                    }}
                    placeholder="e.g. employee.created"
                    className="pl-9"
                  />
                </div>
              </div>
              <div>
                <Label className="text-xs">From</Label>
                <Input
                  type="date"
                  value={filters.from}
                  onChange={(e) => {
                    setFilters((p) => ({ ...p, from: e.target.value }));
                    setPage(1);
                  }}
                  className="mt-1"
                />
              </div>
              <div>
                <Label className="text-xs">To</Label>
                <Input
                  type="date"
                  value={filters.to}
                  onChange={(e) => {
                    setFilters((p) => ({ ...p, to: e.target.value }));
                    setPage(1);
                  }}
                  className="mt-1"
                />
              </div>
            </div>
          </CardContent>
        </Card>

        {isLoading ? (
          <div className="space-y-2">
            {Array.from({ length: 8 }).map((_, i) => (
              <Skeleton key={i} className="h-14 w-full" />
            ))}
          </div>
        ) : logs.length === 0 ? (
          <EmptyState
            icon={Activity}
            title="No audit logs"
            description="No log entries match your filters"
          />
        ) : (
          <>
            <Card>
              <CardContent className="p-0">
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b bg-muted/50">
                        <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">
                          Time
                        </th>
                        <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">
                          Action
                        </th>
                        <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground md:table-cell">
                          Entity
                        </th>
                        <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground lg:table-cell">
                          User
                        </th>
                        <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground lg:table-cell">
                          IP
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {logs.map((log) => (
                        <tr
                          key={log.id}
                          className="border-b last:border-0 hover:bg-muted/30"
                        >
                          <td className="px-4 py-3 text-xs text-muted-foreground whitespace-nowrap">
                            {new Date(log.created_at).toLocaleString()}
                          </td>
                          <td className="px-4 py-3">
                            <Badge
                              variant="outline"
                              className={`border-0 font-mono text-[11px] ${getActionColor(log.action)}`}
                            >
                              {log.action}
                            </Badge>
                          </td>
                          <td className="hidden px-4 py-3 text-xs text-muted-foreground md:table-cell">
                            {log.auditable_type
                              ? `${log.auditable_type.split("\\").pop()}#${log.auditable_id}`
                              : "—"}
                          </td>
                          <td className="hidden px-4 py-3 text-xs text-muted-foreground lg:table-cell">
                            {log.user_id ? `User #${log.user_id}` : "—"}
                          </td>
                          <td className="hidden px-4 py-3 text-xs font-mono text-muted-foreground lg:table-cell">
                            {log.ip_address ?? "—"}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </CardContent>
            </Card>

            {data.meta && data.meta.last_page > 1 && (
              <div className="flex items-center justify-between">
                <p className="text-sm text-muted-foreground">
                  Showing {data.meta.from}–{data.meta.to} of {data.meta.total}
                </p>
                <div className="flex gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page <= 1}
                    onClick={() => setPage(page - 1)}
                  >
                    Previous
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page >= data.meta.last_page}
                    onClick={() => setPage(page + 1)}
                  >
                    Next
                  </Button>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </RoleGate>
  );
}
