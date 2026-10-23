"use client";

import { useState } from "react";
import Link from "next/link";
import {
  Plus,
  Users,
  Download,
  Upload,
  Loader2,
  FileSignature,
  CalendarClock,
  ClipboardCheck,
} from "lucide-react";
import { useMutation } from "@tanstack/react-query";
import type {
  ColumnDef,
  SortingState,
  VisibilityState,
} from "@tanstack/react-table";
import { Button } from "@/components/ui/button";
import { EmployeeAvatar } from "@/components/shared/employee-avatar";
import { PageActions } from "@/components/shared/page-actions";
import { RoleGate } from "@/components/shared/role-gate";
import { SearchInput } from "@/components/shared/search-input";
import { StatusBadge } from "@/components/shared/status-badge";
import { EmptyState } from "@/components/shared/empty-state";
import { SavedViewsMenu } from "@/components/shared/saved-views-menu";
import { FilterBar } from "@/components/shared/filter-bar";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { DataTable } from "@/components/patterns/DataTable";
import { BulkActionsBar } from "@/features/employees/components/bulk-actions-bar";
import { exportEmployees, useEmployees } from "@/features/employees/api";
import { branchesApi, departmentsApi } from "@/features/organization/api";
import { useQueryFilters } from "@/lib/hooks/useQueryFilters";
import { saveCsv } from "@/lib/utils/csv-export";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";
import { useLocalStorage } from "@/lib/hooks/useLocalStorage";
import { useSavedViews } from "@/lib/hooks/useSavedViews";
import type { Employee } from "@/features/employees/types";
import { apiErrorMessage } from "@/lib/api/error-message";

interface EmployeesViewState {
  search: string;
  sort?: string;
  columnVisibility: VisibilityState;
  // Views saved before 2026-10-22 have none of these, and read as "all".
  status?: string;
  department?: string;
  branch?: string;
}

/** `EmployeeStatus`, in the order a person moves through them. */
const STATUSES = [
  "hired",
  "probation",
  "confirmed",
  "suspended",
  "resigned",
  "terminated",
  "retired",
] as const;

/** The columns the API sorts by (`EmployeeController::index`). */
const SORTS = ["name", "employee_code", "status"].flatMap((s) => [s, `-${s}`]);

/**
 * Search, filters and sort live in the address, so a reload keeps them and a
 * filtered list can be sent to a colleague (FRONTEND_REDESIGN_PLAN.md,
 * Phase 4). `all` is the empty choice of each filter.
 */
const FILTER_DEFAULTS = {
  q: "",
  status: "all",
  department: "all",
  branch: "all",
  review: "",
  sort: "name",
};

function parseSort(sort?: string): SortingState {
  if (!sort) return [];
  const desc = sort.startsWith("-");
  return [{ id: desc ? sort.slice(1) : sort, desc }];
}

export default function EmployeesPage() {
  const { t } = useT();
  const filters = useQueryFilters(FILTER_DEFAULTS, {
    status: ["all", ...STATUSES],
    review: ["", "1"],
    sort: SORTS,
  });
  const search = filters.values.q;
  const status = filters.values.status;
  const department = filters.values.department;
  const branch = filters.values.branch;
  const sort = filters.values.sort;
  const [page, setPage] = useState(1);
  // The "check nationality" list: people the 2026-10-14 backfill could not
  // confirm as Ethiopian from their free-text nationality.
  const reviewOnly = filters.values.review === "1";
  const { data: departments } = departmentsApi.useList();
  const { data: branches } = branchesApi.useList();

  /** Any change of search, filter or sort starts again at page 1. */
  function setFilters(patch: Partial<typeof FILTER_DEFAULTS>) {
    filters.set(patch);
    setPage(1);
  }
  const { data: reviewData } = useEmployees({
    per_page: 1,
    nationalityReview: true,
  });
  const reviewCount = reviewData?.meta.total ?? 0;
  const sorting = parseSort(sort);
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

  const isFiltered =
    search !== "" ||
    reviewOnly ||
    status !== "all" ||
    department !== "all" ||
    branch !== "all";

  const listFilters = {
    nationalityReview: reviewOnly,
    status: status === "all" ? undefined : status,
    departmentId: department === "all" ? undefined : department,
    branchId: branch === "all" ? undefined : branch,
  };

  function applyView(state: EmployeesViewState) {
    setFilters({
      q: state.search,
      sort: state.sort ?? "name",
      status: state.status ?? "all",
      department: state.department ?? "all",
      branch: state.branch ?? "all",
    });
    setColumnVisibility(state.columnVisibility);
  }

  const { data, isLoading, isError, refetch } = useEmployees({
    page,
    search,
    per_page: 25,
    sort,
    ...listFilters,
  });

  // It sent only the search, so a list filtered to one department exported
  // every department.
  const exportCsv = useMutation({
    mutationFn: () => exportEmployees({ search, sort, ...listFilters }),
    onSuccess: (data) => {
      saveCsv(
        `employees-${new Date().toISOString().split("T")[0]}.csv`,
        data.csv,
      );
      toast.success(
        t("employees.exported", "Exported :count employees", {
          count: data.count,
        }),
      );
    },
    onError: (err) =>
      toast.error(
        apiErrorMessage(err, t("employees.export_failed", "Export failed")),
      ),
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
        <span className="whitespace-nowrap rounded bg-muted px-2 py-0.5 font-mono text-xs text-muted-foreground">
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
      // From xl: at 1024px with the sidebar open, Position pushed Status
      // (the column a list of people is scanned for) off the right edge.
      meta: { hideBelow: "xl" },
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

  // The same flag the sidebar shows this page under (`employee.create`). It
  // gated on `minRole="hr_admin"`, so a custom role holding the permission
  // was offered the link and then shown "Access Denied".
  return (
    <RoleGate anyPermission={["manageEmployees"]}>
      <div className="space-y-6">
        <PageActions
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
                aria-label={t("common.export", "Export")}
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
                aria-label={t("common.import", "Import")}
              >
                <Link href="/employees/import">
                  <Upload className="h-4 w-4" />
                </Link>
              </Button>
              <Button
                variant="outline"
                size="icon"
                asChild
                title={t("letters.hr_title")}
                aria-label={t("letters.hr_title")}
              >
                <Link href="/employees/letters">
                  <FileSignature className="h-4 w-4" />
                </Link>
              </Button>
              <Button
                variant="outline"
                size="icon"
                asChild
                title={t("nav.exit_clearances", "Exit clearances")}
                aria-label={t("nav.exit_clearances", "Exit clearances")}
              >
                <Link href="/employees/exit-clearances">
                  <ClipboardCheck className="h-4 w-4" />
                </Link>
              </Button>
              <Button
                variant="outline"
                size="icon"
                asChild
                title={t("nav.contracts_ending", "Contracts ending")}
                aria-label={t("nav.contracts_ending", "Contracts ending")}
              >
                <Link href="/employees/contracts">
                  <CalendarClock className="h-4 w-4" />
                </Link>
              </Button>
            </>
          }
        />

        {(reviewCount > 0 || reviewOnly) && (
          <div className="flex flex-col gap-2 rounded-lg border border-warning-edge bg-warning-soft p-3 text-sm text-warning-on-soft sm:flex-row sm:items-center sm:justify-between">
            <p>
              {t("employees.nationality_review_banner", undefined, {
                count: reviewCount,
              })}
            </p>
            <Button
              size="sm"
              variant="outline"
              onClick={() => setFilters({ review: reviewOnly ? "" : "1" })}
            >
              {reviewOnly
                ? t("employees.nationality_review_show_all")
                : t("employees.nationality_review_show")}
            </Button>
          </div>
        )}

        <FilterBar
          activeCount={filters.activeCount - (sort !== "name" ? 1 : 0)}
          onClear={() => {
            // Clearing the filters keeps the sort; it is not a filter.
            setFilters({ ...FILTER_DEFAULTS, sort });
          }}
        >
          <div className="w-full sm:max-w-xs">
            <SearchInput
              value={search}
              onChange={(v) => setFilters({ q: v })}
              placeholder={t(
                "employees.search_placeholder",
                "Search by name, email, or code...",
              )}
            />
          </div>
          <Select
            value={status}
            onValueChange={(v) => setFilters({ status: v })}
          >
            <SelectTrigger
              className="w-full sm:w-44"
              aria-label={t("common.status", "Status")}
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">
                {t("employees.filter_all_statuses", "All statuses")}
              </SelectItem>
              {STATUSES.map((s) => (
                <SelectItem key={s} value={s}>
                  {t(`status.${s}`, s)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select
            value={department}
            onValueChange={(v) => setFilters({ department: v })}
          >
            <SelectTrigger
              className="w-full sm:w-48"
              aria-label={t("common.department", "Department")}
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">
                {t("employees.filter_all_departments", "All departments")}
              </SelectItem>
              {departments?.data?.map((d) => (
                <SelectItem key={d.public_id} value={d.public_id}>
                  {d.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select
            value={branch}
            onValueChange={(v) => setFilters({ branch: v })}
          >
            <SelectTrigger
              className="w-full sm:w-44"
              aria-label={t("org.field.branch", "Branch")}
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">
                {t("employees.filter_all_branches", "All branches")}
              </SelectItem>
              {branches?.data?.map((b) => (
                <SelectItem key={b.public_id} value={b.public_id}>
                  {b.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </FilterBar>

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
            const first = next[0];
            setFilters({
              sort: first ? `${first.desc ? "-" : ""}${first.id}` : "name",
            });
          }}
          columnVisibility={columnVisibility}
          onColumnVisibilityChange={setColumnVisibility}
          onPageChange={setPage}
          toolbarStart={
            data?.meta && (
              <p className="text-sm text-muted-foreground">
                {t("employees.total_count", ":count employees", {
                  count: data.meta.total,
                })}
              </p>
            )
          }
          toolbarExtra={
            <SavedViewsMenu
              views={views}
              onApply={applyView}
              onSave={(name) =>
                saveView(name, {
                  search,
                  sort,
                  columnVisibility,
                  status,
                  department,
                  branch,
                })
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
          // No `getExportRow`: the table's own "Export CSV" downloads only the
          // 25 rows on screen, beside the header's Export that downloads every
          // employee matching the search. Two buttons, one of them silently
          // partial, is how an HR export ends up 25 rows long.
          emptyState={
            <EmptyState
              icon={Users}
              title={t("employees.none_found", "No employees found")}
              description={
                isFiltered
                  ? t(
                      "employees.try_different_filters",
                      "Try a different search or clear the filters",
                    )
                  : t(
                      "employees.add_first",
                      "Add your first employee to get started",
                    )
              }
              action={
                !isFiltered ? (
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
