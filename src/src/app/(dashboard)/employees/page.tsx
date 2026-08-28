"use client";

import { useState } from "react";
import Link from "next/link";
import { Plus, Users, Download, Upload, Loader2 } from "lucide-react";
import { useMutation } from "@tanstack/react-query";
import type {
  ColumnDef,
  SortingState,
  VisibilityState,
} from "@tanstack/react-table";
import { Button } from "@/components/ui/button";
import { EmployeeAvatar } from "@/components/shared/employee-avatar";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { SearchInput } from "@/components/shared/search-input";
import { StatusBadge } from "@/components/shared/status-badge";
import { EmptyState } from "@/components/shared/empty-state";
import { SavedViewsMenu } from "@/components/shared/saved-views-menu";
import { DataTable } from "@/components/patterns/DataTable";
import { BulkActionsBar } from "@/features/employees/components/bulk-actions-bar";
import { useEmployees } from "@/features/employees/api";
import { apiClient } from "@/api/client";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";
import { useLocalStorage } from "@/lib/hooks/useLocalStorage";
import { useSavedViews } from "@/lib/hooks/useSavedViews";
import type { Employee } from "@/features/employees/types";

interface EmployeesViewState {
  search: string;
  sort?: string;
  columnVisibility: VisibilityState;
}

function parseSort(sort?: string): SortingState {
  if (!sort) return [];
  const desc = sort.startsWith("-");
  return [{ id: desc ? sort.slice(1) : sort, desc }];
}

export default function EmployeesPage() {
  const { t } = useT();
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [sorting, setSorting] = useState<SortingState>([
    { id: "name", desc: false },
  ]);
  // Bumped after a bulk action succeeds to remount DataTable and clear its
  // (internally-managed) row selection — column visibility survives the
  // remount because it's now lifted to this page (see below), not internal.
  const [selectionResetKey, setSelectionResetKey] = useState(0);
  // Same key DataTable would use internally, so existing persisted
  // visibility carries over now that saved views need to read/apply it.
  const [columnVisibility, setColumnVisibility] =
    useLocalStorage<VisibilityState>("datatable:employees:visibility", {});
  const {
    views,
    save: saveView,
    remove: removeView,
  } = useSavedViews<EmployeesViewState>("datatable:employees:views");

  const activeSort = sorting[0];
  const sort = activeSort
    ? `${activeSort.desc ? "-" : ""}${activeSort.id}`
    : undefined;

  function applyView(state: EmployeesViewState) {
    setSearch(state.search);
    setSorting(parseSort(state.sort));
    setColumnVisibility(state.columnVisibility);
    setPage(1);
  }

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
      toast.success(
        t("employees.exported", "Exported :count employees", {
          count: data.count,
        }),
      );
    },
    onError: () => toast.error(t("employees.export_failed", "Export failed")),
  });

  const columns: ColumnDef<Employee, unknown>[] = [
    {
      accessorKey: "name",
      header: t("common.name", "Employee"),
      cell: ({ row }) => {
        const employee = row.original;

        return (
          <Link
            href={`/employees/${employee.public_id}`}
            className="flex items-center gap-3"
          >
            <EmployeeAvatar
              name={employee.name}
              photoThumbUrl={employee.photo_thumb_url}
              photoUrl={employee.photo_url}
              className="h-8 w-8"
              fallbackClassName="bg-primary-soft text-xs font-semibold text-primary-on-soft"
            />
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
          title={t("nav.employees", "Employees")}
          description={t(
            "route.employees.desc",
            "Manage your organization's workforce",
          )}
          actions={
            <>
              <Button asChild>
                <Link href="/employees/new">
                  <Plus className="mr-2 h-4 w-4" />
                  {t("employees.add", "Add Employee")}
                </Link>
              </Button>
              <Button
                variant="outline"
                size="icon"
                onClick={() => exportCsv.mutate()}
                disabled={exportCsv.isPending}
                title={t("common.export", "Export")}
              >
                {exportCsv.isPending ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                  <Download className="h-4 w-4" />
                )}
              </Button>
              <Button
                variant="outline"
                size="icon"
                asChild
                title={t("common.import", "Import")}
              >
                <Link href="/employees/import">
                  <Upload className="h-4 w-4" />
                </Link>
              </Button>
            </>
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
              placeholder={t(
                "employees.search_placeholder",
                "Search by name, email, or code...",
              )}
            />
          </div>
          {data?.meta && (
            <p className="text-sm text-muted-foreground">
              {t("employees.total_count", ":count employees", {
                count: data.meta.total,
              })}
            </p>
          )}
        </div>

        <DataTable
          key={selectionResetKey}
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
          columnVisibility={columnVisibility}
          onColumnVisibilityChange={setColumnVisibility}
          onPageChange={setPage}
          toolbarExtra={
            <SavedViewsMenu
              views={views}
              onApply={applyView}
              onSave={(name) =>
                saveView(name, { search, sort, columnVisibility })
              }
              onDelete={removeView}
            />
          }
          selectable
          bulkActions={(selectedIds) => (
            <BulkActionsBar
              selectedIds={selectedIds}
              onComplete={() => setSelectionResetKey((k) => k + 1)}
            />
          )}
          getExportRow={(row) => ({
            [t("employee.code", "Employee Code")]: row.employee_code ?? "",
            [t("common.name", "Name")]: row.name,
            [t("common.email", "Email")]: row.email ?? "",
            [t("common.phone", "Phone")]: row.phone ?? "",
            [t("common.department", "Department")]: row.department?.name ?? "",
            [t("common.position", "Position")]: row.position?.title ?? "",
            [t("common.status", "Status")]: row.status,
            [t("employees.hire_date", "Hire Date")]: row.hire_date,
          })}
          exportFilename="employees"
          emptyState={
            <EmptyState
              icon={Users}
              title={t("employees.none_found", "No employees found")}
              description={
                search
                  ? t(
                      "employees.try_different_search",
                      "Try a different search term",
                    )
                  : t(
                      "employees.add_first",
                      "Add your first employee to get started",
                    )
              }
              action={
                !search ? (
                  <Button asChild>
                    <Link href="/employees/new">
                      <Plus className="mr-2 h-4 w-4" />
                      {t("employees.add", "Add Employee")}
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
