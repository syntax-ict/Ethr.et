"use client";

import { useState } from "react";
import Link from "next/link";
import { Plus, Users, Download, Upload, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { SearchInput } from "@/components/shared/search-input";
import { StatusBadge } from "@/components/shared/status-badge";
import { EmptyState } from "@/components/shared/empty-state";
import { useEmployees } from "@/features/employees/api";
import { useMutation } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { toast } from "sonner";

export default function EmployeesPage() {
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);

  const { data, isLoading } = useEmployees({ page, search, per_page: 25 });

  const exportCsv = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.get<{ csv: string; count: number }>(
        "/employees/export",
        {
          params: { search: search || undefined },
        },
      );
      return data;
    },
    onSuccess: (data) => {
      const blob = new Blob([data.csv], { type: "text/csv" });
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = `employees-${new Date().toISOString().split("T")[0]}.csv`;
      link.click();
      URL.revokeObjectURL(url);
      toast.success(`Exported ${data.count} employees`);
    },
    onError: () => toast.error("Export failed"),
  });

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title="Employees"
          description="Manage your organization's workforce"
          actions={
            <div className="flex flex-wrap gap-2">
              <Button
                variant="outline"
                onClick={() => exportCsv.mutate()}
                disabled={exportCsv.isPending}
              >
                {exportCsv.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <Download className="mr-2 h-4 w-4" />
                )}
                Export
              </Button>
              <Button variant="outline" asChild>
                <Link href="/employees/import">
                  <Upload className="mr-2 h-4 w-4" />
                  Import
                </Link>
              </Button>
              <Button asChild>
                <Link href="/employees/new">
                  <Plus className="mr-2 h-4 w-4" />
                  Add Employee
                </Link>
              </Button>
            </div>
          }
        />

        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="w-full max-w-sm">
            <SearchInput
              value={search}
              onChange={(v) => {
                setSearch(v);
                setPage(1);
              }}
              placeholder="Search by name, email, or code..."
            />
          </div>
          {data?.meta && (
            <p className="text-sm text-muted-foreground">
              {data.meta.total} employee{data.meta.total !== 1 ? "s" : ""}
            </p>
          )}
        </div>

        {isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 8 }).map((_, i) => (
              <Skeleton key={i} className="h-16 w-full rounded-lg" />
            ))}
          </div>
        ) : !data?.data?.length ? (
          <EmptyState
            icon={Users}
            title="No employees found"
            description={
              search
                ? "Try a different search term"
                : "Add your first employee to get started"
            }
            action={
              !search ? (
                <Button asChild>
                  <Link href="/employees/new">
                    <Plus className="mr-2 h-4 w-4" />
                    Add Employee
                  </Link>
                </Button>
              ) : undefined
            }
          />
        ) : (
          <>
            <Card>
              <CardContent className="p-0">
                <div className="overflow-x-auto">
                  <table className="w-full">
                    <thead>
                      <tr className="border-b bg-muted/50">
                        <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                          Employee
                        </th>
                        <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground md:table-cell">
                          Code
                        </th>
                        <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground sm:table-cell">
                          Department
                        </th>
                        <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground lg:table-cell">
                          Position
                        </th>
                        <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                          Status
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.data.map((employee) => {
                        const initials = employee.name
                          .split(" ")
                          .map((n) => n[0])
                          .join("")
                          .slice(0, 2)
                          .toUpperCase();

                        return (
                          <tr
                            key={employee.public_id}
                            className="border-b last:border-0 transition-colors hover:bg-muted/30"
                          >
                            <td className="px-4 py-3">
                              <Link
                                href={`/employees/${employee.public_id}`}
                                className="flex items-center gap-3"
                              >
                                <Avatar className="h-8 w-8">
                                  <AvatarFallback className="text-xs font-medium">
                                    {initials}
                                  </AvatarFallback>
                                </Avatar>
                                <div>
                                  <p className="text-sm font-medium text-foreground hover:text-primary hover:underline">
                                    {employee.name}
                                  </p>
                                  <p className="text-xs text-muted-foreground">
                                    {employee.email}
                                  </p>
                                </div>
                              </Link>
                            </td>
                            <td className="hidden px-4 py-3 md:table-cell">
                              <span className="rounded bg-muted px-2 py-0.5 font-mono text-xs text-muted-foreground">
                                {employee.employee_code}
                              </span>
                            </td>
                            <td className="hidden px-4 py-3 text-sm text-muted-foreground sm:table-cell">
                              {employee.department?.name ?? "—"}
                            </td>
                            <td className="hidden px-4 py-3 text-sm text-muted-foreground lg:table-cell">
                              {employee.position?.name ?? "—"}
                            </td>
                            <td className="px-4 py-3">
                              <StatusBadge status={employee.status} />
                            </td>
                          </tr>
                        );
                      })}
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
