"use client";

import { useState } from "react";
import Link from "next/link";
import { Plus, Users, Download, Upload, Loader2 } from "lucide-react";
import { useMutation } from "@tanstack/react-query";
import type { ColumnDef, SortingState } from "@tanstack/react-table";
import { Button } from "@/components/ui/button";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { SearchInput } from "@/components/shared/search-input";
import { StatusBadge } from "@/components/shared/status-badge";
import { EmptyState } from "@/components/shared/empty-state";
import { DataTable } from "@/components/patterns/DataTable";
import { useEmployees } from "@/features/employees/api";
import { apiClient } from "@/api/client";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";
import type { Employee } from "@/features/employees/types";

export default function EmployeesPage() {
  const { t } = useT();
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [sorting, setSorting] = useState<SortingState>([
    { id: "name", desc: false },
  ]);

  const activeSort = sorting[0];
  const sort = activeSort
    ? `${activeSort.desc ? "-" : ""}${activeSort.id}`
    : undefined;

  const { data, isLoading, isError, refetch } = useEmployees({
    page,
    search,
    per_page: 25,
    sort,
  });

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

  const columns: ColumnDef<Employee, unknown>[] = [
    {
      accessorKey: "name",
      header: t("common.name", "Employee"),
      cell: ({ row }) => {
        const employee = row.original;
        const initials = employee.name
          .split(" ")
          .map((n) => n[0])
          .join("")
          .slice(0, 2)
          .toUpperCase();

        return (
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
              <p className="text-xs text-muted-foreground">{employee.email}</p>
            </div>
          </Link>
        );
      },
    },
    {
      accessorKey: "employee_code",
      header: t("employee.code", "Code"),
      meta: { hideBelow: "md" },
      cell: ({ getValue }) => (
        <span className="rounded bg-muted px-2 py-0.5 font-mono text-xs text-muted-foreground">
          {getValue<string | null>() ?? "—"}
        </span>
      ),
    },
    {
      id: "department",
      header: t("common.department", "Department"),
      meta: { hideBelow: "sm" },
      enableSorting: false,
      cell: ({ row }) => (
        <span className="text-sm text-muted-foreground">
          {row.original.department?.name ?? "—"}
        </span>
      ),
    },
    {
      id: "position",
      header: t("common.position", "Position"),
      meta: { hideBelow: "lg" },
      enableSorting: false,
      cell: ({ row }) => (
        <span className="text-sm text-muted-foreground">
          {row.original.position?.title ?? "—"}
        </span>
      ),
    },
    {
      accessorKey: "status",
      header: t("common.status", "Status"),
      cell: ({ getValue }) => <StatusBadge status={getValue<string>()} />,
    },
  ];

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

        <DataTable
          tableId="employees"
          columns={columns}
          data={data?.data ?? []}
          meta={data?.meta}
          getRowId={(row) => row.public_id}
          isLoading={isLoading}
          isError={isError}
          onRetry={() => refetch()}
          sorting={sorting}
          onSortingChange={(next) => {
            setSorting(next);
            setPage(1);
          }}
          onPageChange={setPage}
          getExportRow={(row) => ({
            "Employee Code": row.employee_code ?? "",
            Name: row.name,
            Email: row.email ?? "",
            Phone: row.phone ?? "",
            Department: row.department?.name ?? "",
            Position: row.position?.title ?? "",
            Status: row.status,
            "Hire Date": row.hire_date,
          })}
          exportFilename="employees"
          emptyState={
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
          }
        />
      </div>
    </RoleGate>
  );
}
